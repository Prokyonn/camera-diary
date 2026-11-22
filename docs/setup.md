# Setup

The five-minute demo with Docker is in the [README](../README.md#try-it). This page covers other AIs for the demo, a PHP install, the AI providers and running it for real.

## The demo with another AI

For a local model, run [Ollama](https://ollama.com) on the host with a vision model (`ollama pull qwen2.5vl:7b`) and start the stack with `AI_PLATFORM=ollama AI_MODEL=qwen2.5vl:7b docker compose up --build`.

Claude Desktop connects to the demo's MCP server through [mcp-remote](https://www.npmjs.com/package/mcp-remote): add `"camera-diary": {"command": "npx", "args": ["mcp-remote", "http://localhost:8080/mcp"]}` to its `mcpServers`. Then ask, e.g., "The person at the car last night was my brother Tom, please label it."

## Install with PHP

You need PHP 8.4 or 8.5 with `gd`, `intl` and `pdo_sqlite`, Composer, and ffmpeg.

First choose the AI and the demo settings in `.env.local` (never committed):

```
CAMERA_DIARY_CONFIG="%kernel.project_dir%/demo/camera-diary.yaml"

# Claude subscription: the claude CLI, logged in
AI_PLATFORM=claude_code
AI_MODEL=sonnet

# or ChatGPT subscription: the codex CLI, logged in; use a model your plan offers
AI_PLATFORM=codex
AI_MODEL=gpt-6.1-sol

# or Gemini
AI_PLATFORM=gemini
AI_MODEL=gemini-3.8-flash
GEMINI_API_KEY=...
```

Then install and load the demo:

```
composer install
mkdir -p var/data
bin/console doctrine:migrations:migrate --no-interaction
bin/console cmsig:seal:index-create
bin/console app:demo                     # or: app:demo --recorded, without an AI
```

The hook takes videos of up to 200 MB: raise `upload_max_filesize` and `post_max_size` in your php.ini to match (the Docker image does, see `docker/php.ini`). Then describe the events and start the web server:

```
bin/console app:process
symfony serve        # or: php -S 127.0.0.1:8000 -t public
```

## AI providers

| `AI_PLATFORM` | How it runs | Needs |
|---|---|---|
| `gemini` | Gemini API with native image input and structured output | `GEMINI_API_KEY` |
| `ollama` | Local model through the Ollama API. Tested with `qwen2.5vl` 7B and 32B on an RTX 4090: valid answers, but far less reliable verdicts than Gemini's (the stranger at the car came out as a false alarm). Good for trying the app offline, not for guarding a home | `OLLAMA_HOST_URL`, a model with vision |
| `claude_code` | `claude -p` from your Claude subscription. It reads the frames with its Read tool and answers against `--json-schema` | the `claude` CLI, logged in |
| `codex` | `codex exec` from your ChatGPT subscription, with `--image` and `--output-schema` | the `codex` CLI, logged in |

The Symfony AI bridges for Claude Code and Codex only pass text. A small adapter (`src/Infrastructure/Ai/Client`) adds the images and the JSON schema that the API platforms get natively. Every provider returns the same structured output.

Google AI Pro and Ultra include monthly Google Cloud credits through the Google Developer Program. They pay for the Gemini API too: apply them to a billing account, link it to the project of your AI Studio key, and set up prepay in AI Studio's billing page. The free trial credit of a new Cloud account does not cover the Gemini API.

The CLI providers run on the machine that runs the worker. The Docker image does not contain them.

Ask and "Summarize this day" call the AI inside the web request. A CLI provider can take minutes for one answer, so PHP's `max_execution_time` (and any proxy timeout) must be long enough for it.

## Running it for real

One image runs everything (`Dockerfile`: FrankenPHP, PHP 8.4, ffmpeg), as the unprivileged user `app` (uid 1000). A data folder mounted from the host must belong to it (`mkdir -p data && sudo chown -R 1000:1000 data`). Where the camera recordings are readable by root only, as with Synology Surveillance Station, run the containers with `user: "0:0"`:

- **Setup, once per start:** `bin/console doctrine:migrations:migrate` and `bin/console cmsig:seal:index-create`.
- **Web:** the image's default command.
- **Worker:** `bin/console messenger:consume scheduler_default scheduler_home_assistant --memory-limit=128M`. It keeps running; only when its memory grows past the limit does it exit, and Docker starts it again.
  - Every minute it describes up to 20 pending events and runs the add-on import.
  - At 03:30 (`APP_TIMEZONE`) it removes false-alarm frames older than 30 days and writes a database backup (14 kept). All of these are [settings](usage.md#settings).

Everything lives in `var/data`: the SQLite database, the Loupe search index, frames, backups, locks and the settings file. Mount it as a volume. `compose.yaml` is the demo; `compose.prod.yaml` is the same layout without demo data, with your own tokens and settings: put the variables it names into `.env.prod.local` and run `docker compose -f compose.prod.yaml up -d --build`.

## Security

The pages have no login. Run them in a private network, e.g. behind Tailscale Serve. The hook needs `INGEST_TOKEN`, and MCP needs `MCP_TOKEN` (or `MCP_OPEN=1`). `TRUSTED_PROXIES` (default `private_ranges`, for Tailscale Serve and Docker) decides whose `X-Forwarded-*` headers count; narrow it to your proxy's address where the private network is shared.
