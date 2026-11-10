# Design

All times are stored in UTC and shown in `APP_TIMEZONE`.

## Layers

`UserInterface → Application → Domain ← Infrastructure`

| Layer | Holds |
|---|---|
| `Domain` | Models (`Camera`, `CameraEvent`, enums) and repository interfaces with filter shapes |
| `Application` | Use cases (`EventIngester`, `EventProcessor`, `PendingEventRunner`, `QuestionAnswerer`, `CameraEventLabeler`, `DeepLinkResolver`, maintenance), ports for everything outside, and the extension points for add-ons |
| `Infrastructure` | Adapters: Doctrine repositories, AI call budget and SQLite backup; ffmpeg; the AI clients; SEAL/Loupe search; the ingest token |
| `UserInterface` | Controllers, the ingest API, forms, console commands, MCP tools, the schedule |

Ports and adapters:

| Port (`Application`) | Adapter (`Infrastructure`) |
|---|---|
| `FrameExtractorInterface`, `FrameStorageInterface` | `Media\FfmpegFrameExtractor`, `Media\FilesystemFrameStorage` |
| `EventDescriberInterface`, `QuestionPlannerInterface`, `AnswerWriterInterface` | `Ai\AiEventDescriber`, `Ai\AiQuestionPlanner`, `Ai\AiAnswerWriter` |
| `AiCallBudgetInterface` | `Doctrine\DbalAiCallBudget` |
| `EventIndexerInterface`, `EventSearchInterface` | `Seal\SealEventIndexer`, `Seal\SealEventSearch` |
| `DatabaseBackupInterface` | `Doctrine\SqliteDatabaseBackup` |
| `UnitOfWorkInterface` | `Doctrine\DoctrineUnitOfWork` |

Repositories only offer `add`, `remove`, `findBy`, `findOneBy`, `getOneBy` and `countBy`. Each Doctrine implementation lists its `ALLOWED_FILTER_KEYS` and rejects other keys.

## Data flow

```
POST /api/events, app:ingest, app:demo ──► EventIngester ──► frames/*.jpg, CameraEvent (pending)
add-on (e.g. Home Assistant) ──► CameraEvent (open) ──► closed ──► FrameSourceInterface ──► frames/*.jpg
                                                                        │
                                       EventProcessor + EventDescriber (AI) ──► CameraEvent (described)
                                                                        │
                                                    EventIndexer ──► SEAL / Loupe
                                                                        │
                                        EventSearch ──► web pages, MCP tools, QuestionAnswerer
```

## Extension points

An add-on is a Symfony bundle. It uses the core through three tagged interfaces and the core use cases:

| Interface | For |
|---|---|
| `FrameSourceInterface` | Events opened without frames get them later, e.g. cut from continuous recordings. Without a source such an event ends as `no_footage`. |
| `HintProviderInterface` | Adds context lines to every event, e.g. "07:16:20 Front door opened". |
| Open events | `new CameraEvent(..., open: true)` takes more detections until the add-on calls `close()`. Only closed events are described. |

`CameraRegistry` creates cameras on first use.

## Ingest

- One video (frames at 25, 50 and 75 % of its length) or up to three images.
- ffmpeg normalizes every frame to a JPEG `FRAME_WIDTH` wide (never upscaled) without metadata.
- The event is open while its frames are written, so the worker never sees it half done. If ffmpeg cannot read the media, the event and its files are removed and the hook answers `422`.
- `source` plus `externalId` is unique. A repeat returns the stored event.

## Description

- `PendingEventRunner` runs every minute under the lock `camera-event-description`. It takes up to 20 closed, pending, due events, newest first.
- `EventProcessor` gets the frames from the event's frame source if the event has none. It then checks the budget, describes the event, stores the result, and indexes it.
- Retries: an AI or ffmpeg failure retries every 10 minutes, and gives `failed` after 8 attempts. Missing footage waits up to an hour, then gives `no_footage`.
- The AI gets the camera's scene, `AI_HOME_CONTEXT`, the hints and the frames. It returns structured output: verdict, false-alarm cause, side, subject, summary.
- Daily budget: `AI_DAILY_CALL_LIMIT` calls per local day, counted atomically in `ai_usage`. Over the limit, events stay pending with their frames, and questions fall back to plain search.
- Manual corrections: a free-text label and a verdict override. The effective verdict is the override, else the AI's.

## AI clients

`StructuredClientFactory` picks the client for `AI_PLATFORM`. Through a service locator, only that platform is built.

| Client | Platforms | Images | Schema |
|---|---|---|---|
| `PlatformStructuredClient` | `gemini`, `vertexai`, `ollama` (AI bundle) | native | native structured output |
| `ClaudeCodeStructuredClient` | `claude_code` (`claude -p`) | listed as files, read with the Read tool (`--tools Read`, `--add-dir`) | `--json-schema` |
| `CodexStructuredClient` | `codex` (`codex exec`) | `--image` | `--output-schema` file |

- **One output path:** the CLI clients use the same strict schema (`ResponseFormatFactory`) and deserializer as the API platforms, so every provider returns the same output classes.
- **Isolated CLI runs:** they run in `var/data` with `--setting-sources project`, `--strict-mcp-config` and `--no-session-persistence` (Claude), or `--ignore-user-config` and `--ephemeral` (Codex). The user's hooks and MCP servers stay out.
- **Model names:** `CliModelCatalog` accepts any model the CLI accepts.

## Search

- SEAL with the Loupe adapter, index `camera_event` in `var/data/search` (`config/schemas/camera_event.php`).
- **Searchable, in rank order:** label, summary, subject, false-alarm cause, hints.
- **Filterable:** camera, detection types, effective verdict, side, status, start time.
- The database is the source of truth. Every change re-indexes the event, and `cmsig:seal:reindex` rebuilds the index.

## Questions

1. The AI turns the question into a search plan: keywords, cameras, time range, side, and whether to include false alarms. It knows the cameras and the current local time.
2. `EventSearch` runs the plan and returns 12 results.
3. The AI gets the question and the top 6 events (text plus 2 frames each). It returns the answer and the ids of the events it relies on, which are listed first.

## Schedule

`messenger:consume scheduler_default scheduler_home_assistant`:

| Message | When | Work |
|---|---|---|
| `DescribePendingEvents` | every minute | `PendingEventRunner` |
| `RunNightlyMaintenance` | 03:30 local | remove false-alarm frames older than 30 days, `VACUUM INTO` a backup (14 kept) |
| `ImportHistory` (add-on) | every minute | Home Assistant import, see the add-on README |

The default schedule is stateful, so a missed nightly run happens after a restart.
