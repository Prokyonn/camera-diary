<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Support;

use App\Domain\Model\DetectionType;
use CameraEvents\HomeAssistant\Application\Settings\CameraMapping;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Application\Settings\PushSettings;
use CameraEvents\HomeAssistant\Application\Settings\SignalMapping;

/**
 * The same settings as tests/Fixtures/home-assistant.yaml.
 */
final class SettingsFactory
{
    public static function create(): HomeAssistantSettings
    {
        return new HomeAssistantSettings(
            [
                new CameraMapping('garden', 'binary_sensor.garden', [DetectionType::Person, DetectionType::Animal], 'Garden', 'Garden with a fence at the back.', 'Garden'),
                new CameraMapping('terrace', 'binary_sensor.terrace', [DetectionType::Person, DetectionType::Animal], 'Terrace', null, 'Terrace'),
            ],
            [
                new SignalMapping('binary_sensor.luna', ['on' => 'Luna came in through the cat flap', 'off' => 'Luna went out through the cat flap'], 'since'),
                new SignalMapping('lock.front_door', ['locked' => 'Front door locked', 'unlocked' => 'Front door unlocked']),
                new SignalMapping('binary_sensor.front_door', ['on' => 'Front door opened', 'off' => 'Front door closed']),
            ],
            new PushSettings('input_text.camera_push', 'mobile_app_phone', '/lovelace/cameras', 'Analysis', 'active', ['CAMERA_MUTE_1H' => "That's us, mute 1 h"]),
        );
    }
}
