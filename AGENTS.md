# AGENTS.md

Camera diary: Symfony 8.1, PHP 8.4, Doctrine ORM on SQLite, Symfony AI, the MCP bundle, Messenger with Scheduler, SEAL with Loupe. The Home Assistant add-on is its own bundle in `addons/home-assistant`. Architecture and its deliberate exceptions: [docs/design.md](docs/design.md).

## Commands

- `composer check` (lint, PHPStan level max without baseline, PHPUnit) passes before every commit.
- `composer fix` runs Rector, then php-cs-fixer (`@Symfony`).
- `bin/console app:demo --recorded` loads the demo without an AI; `docker compose up --build` runs it like a reviewer would.
- `bin/console app:settings` checks a settings file before it ships; a broken one stops the worker.
- Schema changes: `bin/console make:migration`, review it, `doctrine:migrations:migrate`; never `schema:update`.

## Rules

- Layers: UserInterface → Application → Domain ← Infrastructure. Application reaches Doctrine, ffmpeg, the AI and the search only through interfaces; a new exception is written into docs/design.md.
- Repositories offer only `add`, `remove`, `findBy`, `findOneBy`, `getOneBy`, `findFlatBy`, `countBy`, `existBy`, with `ALLOWED_FILTER_KEYS` and shape types.
- Domain and Application get unit tests with Prophecy; Infrastructure and UserInterface functional tests. Every test class is `final` with `#[CoversClass]`.
- A fix starts with a test that fails without it.
- A new Composer package needs the owner's approval; install it with `composer require`, so Flex runs its recipe.

## Traps

- Compose reads the committed `.env` too, so `${VAR:-default}` in `compose.yaml` never sees its default for a variable `.env` sets. Demo values that must win (`MCP_OPEN`, `OLLAMA_HOST_URL`) are fixed in `compose.yaml`.
- The MCP server needs a PSR-17 factory: `nyholm/psr7` is required explicitly, not only through another package.
- The Codex bridge reports only stderr ("Reading additional input from stdin..."); the real reason is the `turn.failed` event, e.g. a model the ChatGPT plan does not offer.
- Secrets go to `.env.local` or `.env.prod.local`; `.private/` is never committed.
