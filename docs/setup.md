# Setup

The Docker demo is in the [README](../README.md#try-it).

## Other AIs for the demo

- Ollama on the host: `ollama pull qwen2.5vl:7b`, then `AI_PLATFORM=ollama AI_MODEL=qwen2.5vl:7b docker compose up --build`.
- Claude Desktop: add `"camera-diary": {"command": "npx", "args": ["mcp-remote", "http://localhost:8080/mcp"]}` to its `mcpServers` ([mcp-remote](https://www.npmjs.com/package/mcp-remote)).

## Install with PHP

- Needs PHP 8.4 or 8.5 with `gd`, `intl` and `pdo_sqlite`, Composer and ffmpeg.
- Pick the AI and the demo settings in `.env.local` (never committed):

```
CAMERA_DIARY_CONFIG="%kernel.project_dir%/demo/camera-diary.yaml"

AI_PLATFORM=gemini              # or claude_code (AI_MODEL=sonnet), codex (AI_MODEL=gpt-6.1-sol)
AI_MODEL=gemini-3.8-flash
GEMINI_API_KEY=...
```

- Install, load the demo, describe it, serve it:

```
composer install
mkdir -p var/data
bin/console doctrine:migrations:migrate --no-interaction
bin/console cmsig:seal:index-create
bin/console app:demo            # or: app:demo --recorded, without an AI
bin/console app:process
symfony serve                   # or: php -S 127.0.0.1:8000 -t public
```

- The hook takes videos up to 200 MB: set `upload_max_filesize` and `post_max_size` to match (the image does, `docker/php.ini`).

## AI providers

| `AI_PLATFORM` | Runs | Needs |
|---|---|---|
| `gemini` | Gemini API, native images and structured output | `GEMINI_API_KEY` |
| `ollama` | Local model; tested with `qwen2.5vl` 7B and 32B on an RTX 4090: valid answers, unreliable verdicts, so for trying it offline only | `OLLAMA_HOST_URL`, a model with vision |
| `claude_code` | `claude -p` with your Claude subscription | the `claude` CLI, logged in |
| `codex` | `codex exec` with your ChatGPT subscription | the `codex` CLI, logged in |

- The Claude Code and Codex bridges pass only text; `src/Infrastructure/Ai/Client` adds the images and the JSON schema.
- The CLI providers run where the worker runs; the Docker image has none.
- Ask and the day summary call the AI in the web request: a CLI provider needs a long `max_execution_time` and proxy timeout.
- Google AI Pro and Ultra's monthly Cloud credits pay for the Gemini API (billing account on the AI Studio key's project, prepay in AI Studio); the free trial credit does not.

## Running it for real

- `compose.prod.yaml`: the demo's layout without demo data; put its variables into `.env.prod.local`, then `docker compose -f compose.prod.yaml up -d --build`.
- One image (FrankenPHP, PHP 8.4, ffmpeg) runs as uid 1000: `mkdir -p data && sudo chown -R 1000:1000 data`.
- Recordings readable by root only (Synology Surveillance Station): run the containers with `user: "0:0"`.
- Each start: `doctrine:migrations:migrate` and `cmsig:seal:index-create`.
- Worker: `messenger:consume scheduler_default scheduler_home_assistant --memory-limit=128M`; it exits only above the limit, and Docker restarts it.
- Every minute: up to 20 events described, the add-on import.
- At 03:30 (`APP_TIMEZONE`): false-alarm frames older than 30 days removed, a database backup written (14 kept); see [settings](usage.md#settings).
- `var/data` holds everything (SQLite, search index, frames, backups, locks, settings): mount it as a volume.

## Security

- The pages have no login: keep them in a private network, e.g. behind Tailscale Serve.
- The hook needs `INGEST_TOKEN`, MCP needs `MCP_TOKEN` (or `MCP_OPEN=1`); make them long and random (`openssl rand -hex 32`).
- `TRUSTED_PROXIES` (default `private_ranges`) decides whose `X-Forwarded-*` headers count; narrow it on a shared network.
