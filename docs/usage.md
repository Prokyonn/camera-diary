# Usage

## Settings

- One YAML file, `var/data/camera-diary.yaml` (or `CAMERA_DIARY_CONFIG`); every key is optional.
- [camera-diary.example.yaml](../camera-diary.example.yaml) lists them all; `bin/console app:settings` checks the file.
- A running worker picks up changes, except `maintenance.nightly_at` (needs a restart).
- `ai.daily_call_limit` (500): AI calls per day; over it, events wait for the next day.
- `ai.call_pools`: a share of the limit per detection type, e.g. 75 for animals, so a spider cannot use up the calls for people.
- `ai.home_context`: free text in every prompt, e.g. "Our cat is called Luna."
- `ai.unusual_rule`: which events are unusual; only those make the add-on's push loud.
- `ai.correction_examples` (6) and `ai.scene_baseline`: your latest corrections and the camera's usual empty view go into each prompt.
- `ai.skip_repeats` (on) and `ai.repeat_recheck_minutes` (120): an ongoing false alarm is filed without an AI call, rechecked after that long.
- `detection_types`, `subjects`, `false_alarm_causes`: merge with the defaults, `name: false` removes one; they are the `enum`s of the AI's output schema.
- `individuals`: your cat or the people who live with you, with subject, description and reference photos (only people who agree).
- More reference photos: drag a box around the animal or person on an event's page; the "Known" page lists and removes them.
- Timings: frame offsets, retries, batch size, question limits, clean-up time, how long frames and backups are kept.

## The hook

Any app that can send a video or images creates events with `INGEST_TOKEN`:

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
| `camera` | Slug: lower-case letters, digits, `-`, `_`; unknown cameras are created |
| `video` or `images[]` | One video (frames at 25, 50 and 75 %) or up to three images |
| `startedAt`, `endedAt` | ISO 8601, `APP_TIMEZONE` without an offset; default now |
| `detections[]` | Names from `detection_types` (default `person`, `animal`, `vehicle`, `motion`) |
| `hints[]` | Context for the AI and the search, e.g. "Front door opened" |
| `externalId`, `source` | Your id and app name; the same pair again returns the stored event |
| `cameraName` | Display name for a new camera |

- Responses: `201` created, `200` a repeat, `401` no valid token, `422` invalid input.
- The worker describes the event on its next run.
- From the CLI: `bin/console app:ingest garden clip.mp4 --detection=animal`.
- Give a camera a scene, so the AI knows where your property ends: `bin/console app:camera garden --name=Garden --scene="Back garden; the fence at the back is the property line"`.

## Home Assistant add-on

- [addons/home-assistant](../addons/home-assistant/README.md) imports detections (e.g. Reolink person and animal sensors) from the Home Assistant history.
- It cuts the frames from continuous recordings, e.g. Synology Surveillance Station.
- A cat flap or door lock becomes a hint; the camera push can be replaced with the AI verdict.
- A separate bundle: the core only knows its extension points (frames, hints, open events, a hook after each description).

## Assistants (MCP)

| `/mcp` tool | Does |
|---|---|
| `search_camera_events` | Search by words, camera, time, subject and known individual |
| `get_camera_event` | One event, with its frames as images |
| `camera_activity` | Exact counts for a time range, per subject, hour, day, camera and false-alarm cause |
| `list_cameras`, `list_known_individuals` | What the cameras see, and who the AI recognises |
| `correct_camera_event` | Set the verdict, cause, known individual or label; the AI learns from it |
| Prompt `daily_report` | A short report of one day |

- Connect with `claude mcp add --transport http camera-diary https://cameras.example/mcp --header "Authorization: Bearer <token>"`, or Home Assistant's "Model Context Protocol" integration.
- Without `MCP_TOKEN` it is closed, unless `MCP_OPEN=1` (the demo and `dev` set it).
- It answers only to `MCP_ALLOWED_HOSTS` (default `localhost,127.0.0.1,[::1]`): add your server's name.
- Results link to the event page under `DEFAULT_URI`.

## Web pages

- `/`: the day's timeline with filters and the AI's summary of the day.
- `/events/{id}`: frames, description, hints and the correction form.
- `/check`: events the AI was unsure of, to confirm or correct.
- `/ask`: questions in plain words; `/ask/{id}` shows an earlier one.
- `/insights`: who came by and when, and the false alarms per camera and cause.
- `/individuals` ("Known"): the known individuals and their reference photos.
- `/at?camera=<slug>&t=<epoch seconds>`: deep link for push notifications; it waits until the event is stored.
