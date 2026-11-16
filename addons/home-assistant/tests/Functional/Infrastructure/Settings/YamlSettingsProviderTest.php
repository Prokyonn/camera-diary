<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Functional\Infrastructure\Settings;

use App\Domain\Model\DetectionType;
use CameraEvents\HomeAssistant\Application\Settings\InvalidSettingsException;
use CameraEvents\HomeAssistant\Infrastructure\Settings\SettingsConfiguration;
use CameraEvents\HomeAssistant\Infrastructure\Settings\YamlSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(YamlSettingsProvider::class)]
#[CoversClass(SettingsConfiguration::class)]
final class YamlSettingsProviderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/camera-events-home-assistant-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->path);
    }

    public function testReadsTheExampleFile(): void
    {
        $settings = new YamlSettingsProvider(\dirname(__DIR__, 4).'/home-assistant.example.yaml')->settings();

        $garden = $settings->camera('garden');
        self::assertNotNull($garden);
        self::assertSame('binary_sensor.garden_person', $garden->sensorEntityId(DetectionType::Person));
        self::assertSame([DetectionType::Person, DetectionType::Animal], $garden->detections);
        self::assertSame('Garden', $garden->recordingFolder);
        $catFlap = $settings->signal('binary_sensor.cat_flap');
        self::assertNotNull($catFlap);
        self::assertSame('since', $catFlap->timeAttribute);
        self::assertSame('The cat came in through the cat flap', $catFlap->describe('on'));
        $frontDoor = $settings->signal('lock.front_door');
        self::assertNotNull($frontDoor);
        self::assertNull($frontDoor->describe('jammed'));
        $push = $settings->push;
        self::assertNotNull($push);
        self::assertSame('input_text.camera_push', $push->handoverEntity);
        self::assertSame('mobile_app_my_phone', $push->notifyService);
        self::assertSame('active', $push->loudInterruptionLevel);
        self::assertSame(['CAMERA_MUTE_1H' => "That's us, mute 1 h"], $push->muteActions);
    }

    public function testPushIsOptional(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n");

        self::assertNull(new YamlSettingsProvider($this->path)->settings()->push);
    }

    public function testIsEmptyWithoutAFile(): void
    {
        self::assertTrue(new YamlSettingsProvider($this->path)->settings()->isEmpty());
        self::assertTrue(new YamlSettingsProvider('')->settings()->isEmpty());
    }

    public function testRejectsAnUnknownDetectionType(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n        detections: [ghost]\n");

        $this->expectException(InvalidSettingsException::class);

        new YamlSettingsProvider($this->path)->settings();
    }

    public function testRejectsAnInvalidCameraSlug(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    Front Door:\n        sensor_prefix: binary_sensor.front_door\n");

        $this->expectException(InvalidSettingsException::class);

        new YamlSettingsProvider($this->path)->settings();
    }
}
