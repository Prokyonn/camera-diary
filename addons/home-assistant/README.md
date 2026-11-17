# Home Assistant add-on

Imports camera detections and context signals from the Home Assistant history. It cuts the frames from continuous recordings. Camera diary describes and indexes the events like any other.

Use it when your cameras record around the clock (e.g. Synology Surveillance Station) and Home Assistant knows when something happened (e.g. the Reolink integration's person and animal sensors). If your camera system can send clips itself, use the [hook](../../README.md#the-hook) instead.

## Setup

1. Create a long-lived access token in Home Assistant (profile, Security). Put it into `.env.local`, or the environment of the container:

   ```
   HA_URL=http://homeassistant.local:8123
   HA_TOKEN=...
   RECORDINGS_DIR=/recordings         # only for frames from continuous recordings
   ```

2. Copy [home-assistant.example.yaml](home-assistant.example.yaml) to `var/data/home-assistant.yaml`, or set `HOME_ASSISTANT_CONFIG` to another path, and map your cameras and signals. The add-on stays idle while the file is missing.
3. Run the worker with both schedules: `bin/console messenger:consume scheduler_default scheduler_home_assistant`.
4. Older history: `bin/console home-assistant:backfill --from=2026-10-01`. How far back you can go depends on Home Assistant's `recorder` `purge_keep_days`.

## What it does

Every minute (`home-assistant:import` runs it once):

1. It reads the state changes since the last run, with 5 minutes of overlap, up to 30 s ago. The first run looks back one hour, and longer ranges go in 6-hour chunks.
2. **Detections:** each change of `<sensor_prefix>_<detection>` to `on` is one detection, unique per camera, type and second. A camera's `detections` are names from `detection_types` in the [Camera diary settings](../../README.md#settings), so a new type there (e.g. `package`) works here too.
3. **Grouping:** detections less than 2 minutes apart form one event, capped at 10 minutes (`import.max_gap`, `import.max_duration`). These events are created open, with the source `home-assistant`.
4. **Closing:** an event is closed once the import is `max_gap` past its end. The core worker then asks this add-on for frames, at the settings' `frame_offsets` (1, 2 and 3 s) after the first detection. The subject often appears only a moment after the camera reacts.
5. **Signals:** state changes of the configured entities become hints for every event within 3 minutes (`import.hint_window`), e.g. "07:17:02 Luna came in through the cat flap". With `time_attribute`, the attribute's time is used instead of the state's, for entities that report late.

## Push with the verdict

If a Home Assistant script already sends a push with a snapshot and a clip for each detection, the add-on replaces it with the AI verdict, usually within two to three minutes:

1. After its push, the script sets an `input_text` (max 255 characters) to `camera|epoch|mute 0/1|tag|group|file base|title|message`, e.g. `garden|1793945800|1|garden-2026-11-06_07-16-40-person|cameras-garden|motion/garden/2026-11-06_07-16-40_person|Person in the garden|Person at 07:16:40.`
   - `file base` is the path of the snapshot (`.jpg`) and the clip (`.mp4`) under Home Assistant's `/media/local`, without the extension.
   - `mute 1` means the push had the buttons listed under `mute_actions`.
2. The import reads the value with the other state changes. The event's frames come from the snapshot and the clip, so it does not wait for the continuous recording, which it still uses when the files are gone.
3. After the description, the add-on calls `notify.<notify_service>` with the same title, tag, group and clip. The message is the original one plus the AI's description. A "Details" button opens the event, and the mute buttons stay.
4. The update is silent (`passive`) unless the AI flags the event as unusual (`ai.unusual_rule` in the settings); then it uses `loud_interruption_level`. Pushes older than an hour (`max_age`) are not updated.
5. `frames` says where each frame comes from: `snapshot`, or a second in the clip. Frames without an entry come from the continuous recording.

Configure it under `push` in the YAML file, see [home-assistant.example.yaml](home-assistant.example.yaml).

## Recordings

The locator expects Synology Surveillance Station's layout: `<RECORDINGS_DIR>/<recording_folder>/<YYYYMMDD><AM|PM>/<name>-<YYYYMMDD>-<HHMMSS>-<epoch_ms>-<n>.mp4`. Files cover up to 30 minutes, and the epoch in the name is their start. If the recording is not there yet, the core retries every 10 minutes for an hour.

Mount the recordings read-only. Only the worker needs them.

## Removing it

Remove `CameraDiary\HomeAssistant\HomeAssistantBundle` from `config/bundles.php` and `camera-diary/home-assistant` from `composer.json`. The core does not depend on it.
