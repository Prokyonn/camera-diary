# Camera diary

Home cameras send dozens of notifications a night, and most of them are a spider in front of the lens. Camera diary turns every clip into a diary entry instead: an AI looks at the frames, files the harmless ones quietly, and wakes you only for what matters.

**[▶ Watch the 80-second video](https://youtu.be/JKazCCm5AbY)**

![The timeline with the day's summary, Luna recognised, the Check page with the standoff and the stranger at the car, an answer with its sources, and the insights, on a phone](docs/images/screens.jpg)

Built with [Symfony AI](https://symfony.com/doc/current/ai/index.html) and the [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk) for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon).

## What it does

- **Real or false alarm:** every event gets a verdict, a cause (spider web, rain, light) and a short description.
- **Only what matters rings:** with the Home Assistant add-on, silent for the spider and the fox, a loud alert for a stranger at the car at night.
- **Your cat by name:** a few reference photos, and events read "Luna" instead of "a cat".
- **It learns from you:** each correction goes into the next prompts, so the same mistake does not come back.
- **Ask in plain words:** "Did a strange cat bother Luna this week?" answers with the events it relies on.
- **Assistants included:** an MCP server lets Claude or any MCP client search the diary and look at the frames itself.

## Try it

```
git clone https://github.com/Prokyonn/camera-diary.git && cd camera-diary
docker compose up --build
```

- Open http://localhost:8080: a week in a made-up, AI-generated home ([credits](demo/CREDITS.md)).
- Without an AI key, you browse the answers recorded from a real run with Gemini.
- With a [free Gemini key](https://aistudio.google.com/apikey), the app describes the 23 clips itself: `export GEMINI_API_KEY=...`, then `docker compose down -v && docker compose up --build`.
- Connect Claude to the same diary, then ask "What did Luna do yesterday? Show me the frames.":

```
claude mcp add --transport http camera-diary http://localhost:8080/mcp
```

## What Symfony AI and the MCP SDK made possible

- **One structured output, four providers:** the answer is a PHP class, its JSON schema's `enum`s come from your settings; Gemini, Ollama, and the Claude Code and Codex CLIs (so a subscription works too).
- **Multimodal prompts that teach:** reference photos, the camera's usual empty view, your latest corrections and hints from the home go with the frames.
- **Images both ways over MCP:** `get_camera_event` returns the frames as `ImageContent`; `correct_camera_event` writes a correction back into the learning loop.
- **The AI plans the search:** a question becomes a structured search plan, and the answer cites the events it used.

## Documentation

- [Setup](docs/setup.md): install with PHP, AI providers, production, security.
- [Usage](docs/usage.md): settings, the hook for any camera, the Home Assistant add-on, the MCP tools, the web pages.
- [Design](docs/design.md): layers, data flow, extension points.

## Development

```
composer check     # lint, phpstan (level max), PHPUnit
composer fix       # Rector, then php-cs-fixer
```

## License

The code is MIT licensed, see [LICENSE](LICENSE). The AI-generated demo clips and photos are CC0, see [demo/CREDITS.md](demo/CREDITS.md).
