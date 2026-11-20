<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Functional\Infrastructure\Settings;

use App\Tests\Support\ProvidesSettings;
use CameraDiary\HomeAssistant\Application\Settings\InvalidSettingsException;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;
use CameraDiary\HomeAssistant\Infrastructure\Settings\SettingsConfiguration;
use CameraDiary\HomeAssistant\Infrastructure\Settings\YamlSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(YamlSettingsProvider::class)]
#[CoversClass(SettingsConfiguration::class)]
final class YamlSettingsProviderTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/camera-diary-home-assistant-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->path);
    }

    public function testReadsTheExampleFile(): void
    {
        $settings = $this->provider(\dirname(__DIR__, 4).'/home-assistant.example.yaml')->settings();

        $garden = $settings->camera('garden');
        self::assertNotNull($garden);
        self::assertSame('binary_sensor.garden_person', $garden->sensorEntityId('person'));
        self::assertSame(['person', 'animal', 'vehicle'], $garden->detections);
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
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n        detections: [person]\n");

        self::assertNull($this->provider($this->path)->settings()->push);
    }

    public function testIsEmptyWithoutAFile(): void
    {
        self::assertTrue($this->provider($this->path)->settings()->isEmpty());
        self::assertTrue($this->provider('')->settings()->isEmpty());
    }

    public function testRejectsAnUnknownDetectionType(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n        detections: [ghost]\n");

        $this->expectException(InvalidSettingsException::class);

        $this->provider($this->path)->settings();
    }

    public function testRejectsAnInvalidCameraSlug(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    Front Door:\n        sensor_prefix: binary_sensor.front_door\n        detections: [person]\n");

        $this->expectException(InvalidSettingsException::class);

        $this->provider($this->path)->settings();
    }

    public function testAcceptsADetectionTypeFromTheCoreVocabulary(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    door:\n        sensor_prefix: binary_sensor.door\n        detections: [package]\n");

        $door = $this->provider($this->path, ['detection_types' => ['package' => ['label' => 'Package']]])->settings()->camera('door');

        self::assertSame('binary_sensor.door_package', $door?->sensorEntityId('package'));
    }

    public function testRequiresTheDetectionsOfACamera(): void
    {
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n");

        $this->expectException(InvalidSettingsException::class);

        $this->provider($this->path)->settings();
    }

    public function testFillsInTheImportAndPushDefaults(): void
    {
        new Filesystem()->dumpFile($this->path, "push:\n    handover_entity: input_text.camera_push\n    notify_service: mobile_app_phone\n");

        $settings = $this->provider($this->path)->settings();

        self::assertEquals(new GroupingRule(120, 600), $settings->grouping);
        self::assertSame(180, $settings->hintWindowSeconds);
        self::assertSame(3600, $settings->push?->maxAgeSeconds);
        self::assertSame([null, 0.5, 3.5], $settings->push->frames);
    }

    public function testParsesTheFileOnlyWhenItChanges(): void
    {
        new Filesystem()->dumpFile($this->path, "push:\n    handover_entity: input_text.first\n    notify_service: mobile_app_phone\n");
        touch($this->path, time() - 10);
        $provider = $this->provider($this->path);
        $first = $provider->settings();
        self::assertSame($first, $provider->settings(), 'The same settings come back while the file is unchanged.');

        new Filesystem()->dumpFile($this->path, "push:\n    handover_entity: input_text.second\n    notify_service: mobile_app_phone\n");
        touch($this->path, time() - 10);
        self::assertSame($first, $provider->settings(), 'Not read again with the same modification time.');

        touch($this->path, time());
        self::assertSame('input_text.second', $provider->settings()->push?->handoverEntity);
    }

    public function testKeepsTheLastValidSettingsWhenAnEditBreaksTheFile(): void
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->error(Argument::containingString('Keeping the last valid Home Assistant settings'), Argument::type('array'))->shouldBeCalledOnce();
        $provider = $this->provider($this->path, logger: $logger->reveal());
        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        sensor_prefix: binary_sensor.garden\n        detections: [person]\n");
        touch($this->path, time() - 10);
        $valid = $provider->settings();

        new Filesystem()->dumpFile($this->path, "cameras:\n    garden:\n        detections: [ghost]\n");
        touch($this->path, time() - 5);

        self::assertSame($valid, $provider->settings());
        self::assertSame($valid, $provider->settings());
    }

    /**
     * @param array<string, mixed> $coreSettings
     */
    private function provider(string $path, array $coreSettings = [], ?LoggerInterface $logger = null): YamlSettingsProvider
    {
        return new YamlSettingsProvider($this->settingsProvider($coreSettings), $path, $logger ?? new NullLogger());
    }
}
