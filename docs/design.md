# Design

All times are stored in UTC and shown in `APP_TIMEZONE`.

## Layers

`UserInterface → Application → Domain ← Infrastructure`

| Layer | Holds |
|---|---|
| `Domain` | Models (`Camera`, `CameraEvent`, enums) and repository interfaces with filter shapes |
| `Application` | Use cases (`EventIngester`, `EventProcessor`, `PendingEventRunner`, `QuestionAnswerer`, `CameraEventLabeler`, `DeepLinkResolver`, maintenance), the settings, ports for everything outside, and the extension points for add-ons |
| `Infrastructure` | Adapters: Doctrine repositories, AI call budget and SQLite backup; ffmpeg; the AI clients; SEAL/Loupe search; the ingest token; the settings file |
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
| `SettingsProviderInterface` | `Settings\YamlSettingsProvider` |

Repositories only offer `add`, `remove`, `findBy`, `findOneBy`, `getOneBy` and `countBy`. Each Doctrine implementation lists its `ALLOWED_FILTER_KEYS` and rejects other keys.

## Settings

`SettingsProviderInterface` returns one `Settings` object: the vocabularies, the AI limits and context, and the timings. `YamlSettingsProvider` reads `CAMERA_DIARY_CONFIG`, validates it against `SettingsConfiguration` (defaults for everything left out) and reads it again when the file changes. Nothing else reads the environment for these values.

- **Vocabularies:** detection types, subjects and false-alarm causes are configured `Term`s (name, label, hint for the AI). Events store the names; the domain only checks their form (`TermName`). Verdict, side and status stay enums because the code branches on them.
- **AI output:** fields marked `#[VocabularyChoice]` get the configured names as their JSON schema `enum`, through `VocabularyResponseFormatFactory`, which decorates Symfony AI's response format factory. The CLI clients use the same factory.
- **Known individuals:** `individuals` are configured `Individual`s (name, label, subject, description, reference photo paths resolved against the settings file's folder, detection types). They form the `Individuals` vocabulary, so the AI output field `individual` takes its `enum` the same way, plus the reserved `unknown`. Photos added in the app live in `<data>/individuals/<name>/` (`ReferencePhotoStoreInterface`); `ReferencePhotos` puts the settings' ones first, then the newest added, and gives the AI at most `ai.reference_photos` per individual whose detection types match the event.
- **Labels:** `{{ name|term_label('subjects') }}` in Twig; names no longer configured read as words. `{{ subject_label(event) }}` reads "Luna", "Other cat" (a subject with known individuals) or the subject's label; the search index uses the same text.

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

An add-on is a Symfony bundle. It uses the core through these extension points and the core use cases:

| Interface | For |
|---|---|
| `FrameSourceInterface` | Events opened without frames get them later, e.g. cut from continuous recordings. Without a source such an event ends as `no_footage`. |
| `HintProviderInterface` | Adds context lines to every event, e.g. "07:16:20 Front door opened". |
| Open events | `new CameraEvent(..., open: true)` takes more detections until the add-on calls `close()`. Only closed events are described. |
| `EventDescribed` | Dispatched after an event's description is stored and indexed, e.g. to send a notification. |

`CameraRegistry` creates cameras on first use.

## Ingest

- One video (frames at 25, 50 and 75 % of its length) or up to three images.
- ffmpeg normalizes every frame to a JPEG `FRAME_WIDTH` wide (never upscaled) without metadata.
- The event is open while its frames are written, so the worker never sees it half done. If ffmpeg cannot read the media, the event and its files are removed and the hook answers `422`.
- `source` plus `externalId` is unique. A repeat returns the stored event.

## Description

- `PendingEventRunner` runs every minute under the lock `camera-event-description`. It takes up to `description.batch_size` (20) closed, pending, due events, newest first.
- `EventProcessor` gets the frames from the event's frame source if the event has none. It then checks the budget, describes the event, stores the result, and indexes it.
- Retries: an AI or ffmpeg failure retries every 10 minutes, and gives `failed` after 8 attempts. Missing footage waits up to an hour, then gives `no_footage`. All three are settings (`description.*`).
- The AI gets the camera's scene, the settings' home context, unusual rule and false-alarm causes, the reference photos of the known individuals, the hints and the frames. It returns structured output: verdict, false-alarm cause, side, subject, the known individual (or `unknown`), summary, whether the event is unusual, and its confidence. An individual of another subject than the answer's is dropped.
- Daily budget: `ai.daily_call_limit` calls per local day, counted atomically in `ai_usage`. An event in a call pool (the first pool with one of its detection types) also needs room in that pool, counted in `ai_pool_usage`; the runner skips such events and goes on with the others. Over the daily limit, events stay pending with their frames, and questions fall back to plain search.
- Manual corrections: a free-text label, a verdict override and an individual override (a name, or `unknown` for nobody known). The effective values are the overrides, else the AI's; a new description keeps them.
- **Learning from the owner:** the AI also returns its confidence. Described events below `high` that nobody reviewed are listed on `/check` (`needsReview`); confirming or correcting sets `reviewedAt`. `PastCorrections` gives the AI the latest `ai.correction_examples` reviewed events of the same camera whose answer the owner changed (`isCorrected()`), as text: what the AI said, and what the owner set.
- **Usual view:** `SceneBaselines` sends the first frame of the camera's latest false alarm with the cause `nothing`, if its light matches the event (`FrameStorageInterface::isMonochrome()`: infrared night frames have no colour). The AI is told to look for what is new or has moved. `ai.scene_baseline: false` turns it off.

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

1. The AI turns the question into a search plan: subjects and known individuals (from the settings, as exact filters), keywords for further details, cameras, time range, side, and whether to include false alarms. It knows the cameras and the current local time.
2. With a time range of at most `questions.context_events` (200) events, all of them are read, the keyword matches first, so wording cannot hide an event. Otherwise `EventSearch` returns the 12 best keyword matches. Either way the search reports the total.
3. The AI gets the question, the total and every event as text (subject, description, label, hints), with 2 frames each for the first 6. It returns the answer and the ids of the events it relies on, which are listed first; the page shows 12.

## Schedule

`messenger:consume scheduler_default scheduler_home_assistant`:

| Message | When | Work |
|---|---|---|
| `DescribePendingEvents` | every minute | `PendingEventRunner` |
| `RunNightlyMaintenance` | `maintenance.nightly_at` (03:30 local) | remove false-alarm frames older than 30 days, `VACUUM INTO` a backup (14 kept); both configurable |
| `ImportHistory` (add-on) | every minute | Home Assistant import, see the add-on README |

The default schedule is stateful, so a missed nightly run happens after a restart.
