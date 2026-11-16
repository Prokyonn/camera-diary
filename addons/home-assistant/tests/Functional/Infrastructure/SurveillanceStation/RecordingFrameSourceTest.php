<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Functional\Infrastructure\SurveillanceStation;

use App\Application\Clock\HomeTimeZone;
use App\Application\Frame\FootageNotFoundException;
use App\Application\Frame\FrameExtractionFailedException;
use App\Infrastructure\Media\FfmpegFrameExtractor;
use App\Tests\Factory\CameraEventFactory;
use CameraDiary\HomeAssistant\Application\Import\EventGrouper;
use CameraDiary\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation\RecordingFrameSource;
use CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation\RecordingLocator;
use CameraDiary\HomeAssistant\Tests\Support\RecordingFixture;
use CameraDiary\HomeAssistant\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RecordingFrameSource::class)]
final class RecordingFrameSourceTest extends TestCase
{
    use ProphecyTrait;

    private RecordingFixture $recordings;

    private string $frame;

    protected function setUp(): void
    {
        if (null === RecordingFixture::ffmpeg()) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $suffix = bin2hex(random_bytes(4));
        $this->recordings = new RecordingFixture(sys_get_temp_dir().'/camera-diary-recordings-'.$suffix);
        new Filesystem()->mkdir($this->recordings->dir);
        $this->frame = sys_get_temp_dir().'/camera-diary-frame-'.$suffix.'.jpg';
    }

    protected function tearDown(): void
    {
        $this->recordings->remove();
        new Filesystem()->remove($this->frame);
    }

    public function testSupportsHomeAssistantEventsOfCamerasWithRecordings(): void
    {
        self::assertTrue($this->source()->supports(CameraEventFactory::create(camera: 'terrace', source: EventGrouper::SOURCE)));
        self::assertFalse($this->source()->supports(CameraEventFactory::create(camera: 'terrace', source: 'api')));
        self::assertFalse($this->source()->supports(CameraEventFactory::create(camera: 'porch', source: EventGrouper::SOURCE)));
    }

    public function testCutsAScaledJpegAtTheMoment(): void
    {
        $this->recordings->video('Terrace', CameraEventFactory::utc('2026-11-06 06:16:35'));

        $this->source()->grab(CameraEventFactory::create(camera: 'terrace', source: EventGrouper::SOURCE), CameraEventFactory::utc('2026-11-06 06:16:41'), $this->frame);

        $size = getimagesize($this->frame);
        self::assertIsArray($size);
        self::assertSame([320, 180], [$size[0], $size[1]]);
        self::assertSame('image/jpeg', $size['mime']);
    }

    public function testReportsMissingFootage(): void
    {
        $this->expectException(FootageNotFoundException::class);

        $this->source()->grab(CameraEventFactory::create(camera: 'garden', source: EventGrouper::SOURCE), CameraEventFactory::utc('2026-11-06 06:16:41'), $this->frame);
    }

    public function testReportsAMomentBeyondTheEndOfTheFile(): void
    {
        $this->recordings->video('Terrace', CameraEventFactory::utc('2026-11-06 06:16:35'), 2);

        $this->expectException(FrameExtractionFailedException::class);

        $this->source()->grab(CameraEventFactory::create(camera: 'terrace', source: EventGrouper::SOURCE), CameraEventFactory::utc('2026-11-06 06:16:41'), $this->frame);
    }

    private function source(): RecordingFrameSource
    {
        $settings = $this->prophesize(SettingsProviderInterface::class);
        $settings->settings()->willReturn(SettingsFactory::create());

        return new RecordingFrameSource(
            $settings->reveal(),
            new RecordingLocator($this->recordings->dir, new HomeTimeZone('Europe/Vienna')),
            new FfmpegFrameExtractor(RecordingFixture::ffmpeg() ?? 'ffmpeg', 'ffprobe', 320),
        );
    }
}
