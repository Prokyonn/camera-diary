<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Functional\Infrastructure\SurveillanceStation;

use App\Application\Clock\HomeTimeZone;
use App\Tests\Factory\CameraEventFactory;
use CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation\Recording;
use CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation\RecordingLocator;
use CameraDiary\HomeAssistant\Tests\Support\RecordingFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingLocator::class)]
#[CoversClass(Recording::class)]
final class RecordingLocatorTest extends TestCase
{
    private RecordingFixture $recordings;

    protected function setUp(): void
    {
        $this->recordings = new RecordingFixture(sys_get_temp_dir().'/camera-diary-recordings-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        $this->recordings->remove();
    }

    public function testFindsTheFileThatContainsTheMoment(): void
    {
        $this->recordings->placeholder('Terrace', CameraEventFactory::utc('2026-11-06 05:30:00'));
        $expected = $this->recordings->placeholder('Terrace', CameraEventFactory::utc('2026-11-06 06:00:00'));
        $this->recordings->placeholder('Garden', CameraEventFactory::utc('2026-11-06 06:00:00'));

        $recording = $this->locator()->locate('Terrace', CameraEventFactory::utc('2026-11-06 06:16:41'));

        self::assertInstanceOf(Recording::class, $recording);
        self::assertSame($expected, $recording->path);
        self::assertEqualsWithDelta(1001.0, $recording->offsetSeconds(CameraEventFactory::utc('2026-11-06 06:16:41')), \PHP_FLOAT_EPSILON);
    }

    public function testLooksIntoTheMorningFolderJustAfterNoon(): void
    {
        $expected = $this->recordings->placeholder('Garden', CameraEventFactory::utc('2026-11-06 10:45:00'));

        $recording = $this->locator()->locate('Garden', CameraEventFactory::utc('2026-11-06 11:05:00'));

        self::assertStringContainsString('/20261106AM/', $expected);
        self::assertSame($expected, $recording?->path);
    }

    public function testReturnsNullWhenTheFootageIsGone(): void
    {
        $this->recordings->placeholder('Garden', CameraEventFactory::utc('2026-11-06 06:00:00'));

        self::assertNull($this->locator()->locate('Garden', CameraEventFactory::utc('2026-11-06 06:45:00')), 'A file covers 30 minutes.');
        self::assertNull($this->locator()->locate('Terrace', CameraEventFactory::utc('2026-11-06 06:10:00')));
    }

    private function locator(): RecordingLocator
    {
        return new RecordingLocator($this->recordings->dir, new HomeTimeZone('Europe/Vienna'));
    }
}
