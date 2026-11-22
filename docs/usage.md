# Usage

Settings, the hook for any camera, the Home Assistant add-on, the MCP server and the web pages.

## Settings

Everything an installation may want to change lives in one YAML file, `var/data/camera-diary.yaml` (or `CAMERA_DIARY_CONFIG`). Every key is optional, and a running worker picks up changes, except `maintenance.nightly_at`: the schedule is built when the worker starts. [camera-diary.example.yaml](../camera-diary.example.yaml) lists them all; `bin/console app:settings` checks the file.

- **AI limits:** `ai.daily_call_limit` (default 500) caps the calls per day; events over it wait for the next day. `ai.call_pools` gives detection types a share of it, e.g. 75 calls for animals, so a spider web in front of the lens cannot use up the calls for people.
- **Learning:** `ai.correction_examples` (6) of your latest corrections on the same camera go with each event; `ai.scene_baseline` sends the camera's latest empty view (a false alarm with nothing visible) in the same light, day or infrared night. `ai.skip_repeats` (on) files repeats of an ongoing false alarm without an AI call; `ai.repeat_recheck_minutes` (120, 0 for never) sends one to the AI anyway after that long.
- **AI context:** `ai.home_context` is free text every prompt gets, e.g. "Our cat is called Luna." `ai.unusual_rule` tells the AI which events to flag as unusual; the Home Assistant add-on makes a sound only for those.
- **Vocabularies:** `detection_types`, `subjects` and `false_alarm_causes` merge with the defaults. Add a name with a label and a hint for the AI, or remove one with `name: false`. The AI's output schema takes its allowed values from these lists.
- **Known individuals:** `individuals` names, e.g., your cat or the people who live with you, with the subject, a short description and optionally reference photos (paths relative to the settings file). More photos come from the app: on an event's page, drag a box around the animal or person and save it; the "Known" page lists and removes them. The AI gets the photos with an event and names an individual only on a clear match; events then show "Luna", and other cats "Other cat". `detection_types` limits whose photos go with which events (the cat's with `animal`), and `ai.reference_photos` (6) caps them per individual. Crops that show the animal or person large work best, from different days and, for people, in different clothes. Only add people who agree to it. A wrong answer can be corrected on the event page.
- **Timings:** frame offsets, retries, batch size, question limits, the nightly clean-up time and how long false-alarm frames and backups are kept.

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
| `startedAt`, `endedAt` | ISO 8601, in `APP_TIMEZONE` without an offset; default now |
| `detections[]` | Names from `detection_types` in the settings (default `person`, `animal`, `vehicle`, `motion`) |
| `hints[]` | Context for the AI and the search, e.g. "Front door opened" |
| `externalId`, `source` | Your id and app name; sending the same pair again returns the stored event |
| `cameraName` | Display name for a new camera |

The response is `201` with `{"id", "status", "created", "url"}`, `200` for a repeat, `401` without a valid token, and `422` for invalid input. The worker describes the event on its next run. The CLI does the same: `bin/console app:ingest garden clip.mp4 --detection=animal`.

Give cameras a scene, so the AI knows what normal looks like and where your property ends:

```
bin/console app:camera garden --name=Garden --scene="Back garden; the fence at the back is the property line"
```

## Home Assistant add-on

[addons/home-assistant](../addons/home-assistant/README.md) imports detections (e.g. Reolink person and animal sensors) from the Home Assistant history. It groups them into events and cuts frames 1, 2 and 3 s after an event's first detection from continuous recordings, e.g. Synology Surveillance Station. It also turns state changes such as a cat flap or a door lock into hints. It can also replace your camera push with the AI verdict a minute or two later. It is a separate Symfony bundle: the core only knows its extension points (frames, hints, open events, a hook after each description).

## Assistants (MCP)

`/mcp` serves these tools and one prompt:

| Tool | Does |
|---|---|
| `search_camera_events` | Search by words, camera, time, subject and known individual; returns the events and the total |
| `get_camera_event` | One event, with its frames as images, so the assistant looks at them itself |
| `camera_activity` | Exact counts for a time range: per subject or known individual, hour, day, camera and false-alarm cause |
| `list_cameras`, `list_known_individuals` | What the cameras see, and who the AI recognises |
| `correct_camera_event` | Set the verdict, the false-alarm cause, the known individual or a label; the AI learns from it |
| Prompt `daily_report` | Asks the assistant for a short report of one day, using the tools above |

Home Assistant's "Model Context Protocol" integration connects Assist to it, and so does any other MCP client. Results link to the event page (`DEFAULT_URI`). It answers only to the host names in `MCP_ALLOWED_HOSTS` (DNS rebinding protection; default `localhost,127.0.0.1,[::1]`): add your server's name when an assistant on another machine connects. It needs the bearer token `MCP_TOKEN`, long and random (`openssl rand -hex 32`; nothing limits wrong attempts), e.g. `claude mcp add --transport http camera-diary https://cameras.example/mcp --header "Authorization: Bearer <token>"`. Without a token it is closed, unless `MCP_OPEN=1` opens it: the demo's `compose.yaml` and the `dev` environment do, so the README's example works without one.

## Web pages

- `/`: timeline per day with filters, false alarms hidden by default, and the AI's summary of the day on request.
- `/events/{id}`: frames, description, hints, and the correction form (label, verdict, cause, who).
- `/check`: events the AI was not sure of, to confirm or correct.
- `/ask`: questions in plain words; `/ask/{id}` shows an earlier question with its answer and events.
- `/insights`: who came by and when, and the false alarms per camera and cause, for 7 or 30 days.
- `/individuals` ("Known"): the known individuals and their reference photos, to add or remove.
- `/at?camera=<slug>&t=<epoch seconds>`: deep link for push notifications. It waits and refreshes while the event is not stored yet.
