# Camera diary

Your security cameras record everything and tell you nothing. Camera diary turns each clip into a searchable diary entry: an AI looks at the frames and writes down what happened, and you ask in plain words.

> When was the strange grey cat here?
> Was anyone behind the fence last night?
> Show me everything on the terrace this morning except the rain.

Built with [Symfony AI](https://symfony.com/doc/current/ai/index.html) and the [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).

- **Any camera, any app.** Anything that can send a video or an image posts it to one hook. A Home Assistant add-on comes along, cutting frames from continuous recordings.
- **Any AI, including your subscription.** Gemini, Vertex AI or Ollama through their APIs, or Claude Code and Codex through the CLI you are already logged in to.
- **Real or false alarm.** Every event gets a verdict, the cause of a false alarm (spider web, insect, rain, light), where it happened, and a short description. You can correct both the label and the verdict.
- **Questions with sources.** The AI turns the question into a search, then looks at the best matches' frames and answers with the events it relies on.
- **Assistants included.** An MCP server lets Home Assistant Assist, Claude or any MCP client search the events.
- **Phone first.** A timeline per day, the event page with frames, a question box, and a deep link for push notifications.

## Try it in five minutes

The demo loads five openly licensed clips: a badger in a garden at night, fox cubs in a hedge, a fox at a wildlife camera, a person with an umbrella in the snow, and rain over a courtyard, see [demo/CREDITS.md](demo/CREDITS.md).

### With Docker

```
git clone <this repository> camera-diary && cd camera-diary
export GEMINI_API_KEY=...        # free key: https://aistudio.google.com/apikey
docker compose up --build
```

Open http://localhost:8080. The worker describes the events within a minute. Then ask something on the "Ask" page.

For a local model instead, run [Ollama](https://ollama.com) on the host with a vision model (`ollama pull qwen2.5vl:7b`) and start the stack with `AI_PLATFORM=ollama AI_MODEL=qwen2.5vl:7b docker compose up --build`.

### With PHP

You need PHP 8.4 with `intl` and `pdo_sqlite`, Composer, and ffmpeg.

```
composer install
mkdir -p var/data
bin/console doctrine:migrations:migrate --no-interaction
bin/console cmsig:seal:index-create
bin/console app:demo
```

Choose the AI in `.env.local` (never committed):

```
# Claude subscription: the claude CLI, logged in
AI_PLATFORM=claude_code
AI_MODEL=sonnet

# or ChatGPT subscription: the codex CLI, logged in; use a model your plan offers
AI_PLATFORM=codex
AI_MODEL=gpt-6.1-sol

# or Gemini
AI_PLATFORM=gemini
AI_MODEL=gemini-flash-latest
GEMINI_API_KEY=...

# or Gemini on Vertex AI (Google Cloud billing, e.g. a subscription's monthly credits)
AI_PLATFORM=vertexai
AI_MODEL=gemini-2.5-flash
VERTEX_AI_API_KEY=...
```

Then describe the events and start the web server:

```
bin/console app:process
symfony serve        # or: php -S 127.0.0.1:8000 -t public
```

## AI providers

| `AI_PLATFORM` | How it runs | Needs |
|---|---|---|
| `gemini` | Gemini API with native image input and structured output | `GEMINI_API_KEY` |
| `vertexai` | Gemini on Vertex AI, e.g. paid from the Google Cloud credits of a Google AI subscription; `AI_MODEL=gemini-2.5-flash` | `VERTEX_AI_API_KEY` |
| `ollama` | Local model through the Ollama API | `OLLAMA_HOST_URL`, a model with vision |
| `claude_code` | `claude -p` from your Claude subscription. It reads the frames with its Read tool and answers against `--json-schema` | the `claude` CLI, logged in |
| `codex` | `codex exec` from your ChatGPT subscription, with `--image` and `--output-schema` | the `codex` CLI, logged in |

The Symfony AI bridges for Claude Code and Codex only pass text. A small adapter (`src/Infrastructure/Ai/Client`) adds the images and the JSON schema that the API platforms get natively. Every provider returns the same structured output.

Google AI Pro and Ultra include monthly Google Cloud credits through the Google Developer Program. They pay for the Gemini API too: apply them to a billing account, link it to the project of your AI Studio key, and set up prepay in AI Studio's billing page. The free trial credit of a new Cloud account does not cover the Gemini API.

For `vertexai`, enable the Agent Platform API (formerly Vertex AI) in a project with billing, and create an API key bound to a service account with the role `aiplatform.user`. Google's default organization policy blocks such keys for private accounts, so `gemini` is the simpler choice there.

`AI_DAILY_CALL_LIMIT` caps the AI calls per day (default 500). Events over the cap wait for the next day. `AI_HOME_CONTEXT` is free text every prompt gets, e.g. "Our cat is called Luna." `AI_UNUSUAL_RULE` tells the AI which events to flag as unusual; the Home Assistant add-on makes a sound only for those.

The CLI providers run on the machine that runs the worker. The Docker image does not contain them.

## The hook

Any application that can send a video or images can create events. Set `INGEST_TOKEN` and post multipart form data:

```
curl -H "Authorization: Bearer $INGEST_TOKEN" \
     -F camera=front-door -F cameraName="Front door" \
     -F startedAt=2026-11-10T07:16:20+01:00 \
     -F 'detections[]=person' -F 'hints[]=Doorbell rang' \
     -F video=@clip.mp4 \
     http://localhost:8080/api/events
```

| Field | |
|---|---|
| `camera` | Slug: lower-case letters, digits, `-`, `_`. Unknown cameras are created. |
| `video` or `images[]` | One video (frames are taken at 25, 50 and 75 % of it) or up to three images |
| `startedAt`, `endedAt` | ISO 8601; default now |
| `detections[]` | `person`, `animal`, `vehicle`, `motion` |
| `hints[]` | Context for the AI and the search, e.g. "Front door opened" |
| `externalId`, `source` | Your id and app name; sending the same pair again returns the stored event |
| `cameraName` | Display name for a new camera |

The response is `201` with `{"id", "status", "created", "url"}`, `200` for a repeat, `401` without a valid token, and `422` for invalid input. The worker describes the event on its next run. The CLI does the same: `bin/console app:ingest garden clip.mp4 --detection=animal`.

Give cameras a scene, so the AI knows what normal looks like and where your property ends:

```
bin/console app:camera garden --name=Garden --scene="Back garden; the fence at the back is the property line"
```

## Home Assistant add-on

[addons/home-assistant](addons/home-assistant/README.md) imports detections (e.g. Reolink person and animal sensors) from the Home Assistant history. It groups them into events and cuts frames 1, 2 and 3 s after each detection from continuous recordings, e.g. Synology Surveillance Station. It also turns state changes such as a cat flap or a door lock into hints. It can also replace your camera push with the AI verdict a minute or two later. It is a separate Symfony bundle: the core only knows its extension points (frames, hints, open events, a hook after each description).

## Assistants (MCP)

`/mcp` serves the tools `search_camera_events`, `get_camera_event` and `list_cameras`. Home Assistant's "Model Context Protocol" integration connects Assist to it, and so does any other MCP client. Results link to the event page (`DEFAULT_URI`). Only `localhost` is an allowed host by default, see `config/packages/mcp.yaml`.

## Web pages

- `/`: timeline per day with filters, false alarms hidden by default.
- `/events/{id}`: frames, description, hints, and the correction form (label and verdict).
- `/ask`: questions in plain words.
- `/at?camera=<slug>&t=<epoch seconds>`: deep link for push notifications. It waits and refreshes while the event is not stored yet.

The pages and MCP have no login. Run them in a private network, e.g. behind Tailscale Serve. Only the hook needs a credential.

## Running it for real

One image runs everything (`Dockerfile`: FrankenPHP, PHP 8.4, ffmpeg):

- **Setup, once per start:** `bin/console doctrine:migrations:migrate` and `bin/console cmsig:seal:index-create`.
- **Web:** the image's default command.
- **Worker:** `bin/console messenger:consume scheduler_default scheduler_home_assistant`.
  - Every minute it describes up to 20 pending events and runs the add-on import.
  - At 03:30 (`APP_TIMEZONE`) it removes false-alarm frames older than 30 days and writes a database backup (14 kept).

Everything lives in `var/data`: the SQLite database, the Loupe search index, frames, backups and locks. Mount it as a volume. `compose.yaml` shows the layout.

## Development

```
composer fix       # Rector, then php-cs-fixer
composer phpstan   # level max, strict rules
composer lint      # cs and Rector dry runs, container, YAML, Twig, Doctrine schema
composer test      # PHPUnit
composer check     # lint, phpstan, test
```

The tests use a scripted AI platform (`tests/Support/FakePlatform`), a fake Home Assistant, and real ffmpeg on generated videos. How the parts fit together: [docs/design.md](docs/design.md).

## License

The code is MIT licensed, see [LICENSE](LICENSE). The demo clips keep their own licenses, see [demo/CREDITS.md](demo/CREDITS.md).
