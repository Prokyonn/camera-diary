<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Support;

use CameraDiary\HomeAssistant\Application\Settings\CameraMapping;
use CameraDiary\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraDiary\HomeAssistant\Application\Settings\PushSettings;
use CameraDiary\HomeAssistant\Application\Settings\SignalMapping;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;

/**
 * The same settings as tests/Fixtures/home-assistant.yaml.
 */
final class SettingsFactory
{
    public static function create(): HomeAssistantSettings
    {
        return new HomeAssistantSettings(
            [
                new CameraMapping('garden', 'binary_sensor.garden', ['person', 'animal'], 'Garden', 'Garden with a fence at the back.', 'Garden'),
                new CameraMapping('terrace', 'binary_sensor.terrace', ['person', 'animal'], 'Terrace', null, 'Terrace'),
            ],
            [
                new SignalMapping('binary_sensor.luna', ['on' => 'Luna came in through the cat flap', 'off' => 'Luna went out through the cat flap'], 'since'),
                new SignalMapping('lock.front_door', ['locked' => 'Front door locked', 'unlocked' => 'Front door unlocked']),
                new SignalMapping('binary_sensor.front_door', ['on' => 'Front door opened', 'off' => 'Front door closed']),
            ],
            new GroupingRule(120, 600),
            180,
            new PushSettings('input_text.camera_push', 'mobile_app_phone', '/lovelace/cameras', 'Analysis', 'active', ['CAMERA_MUTE_1H' => "That's us, mute 1 h"], 3600, [null, 0.5, 3.5]),
        );
    }
}
