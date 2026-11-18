<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Description\SceneBaselines;
use App\Application\Frame\FrameStorageInterface;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(SceneBaselines::class)]
final class SceneBaselinesTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    public function testPicksTheLatestEmptyViewInTheSameLight(): void
    {
        $event = CameraEventFactory::withFrames('2026-11-06 22:00:00', id: 1);
        $day = CameraEventFactory::described('The empty terrace by day.', '2026-11-06 12:00:00', verdict: Verdict::FalseAlarm, falseAlarmCause: 'nothing', id: 2);
        $web = CameraEventFactory::described('A spider web.', '2026-11-06 21:00:00', verdict: Verdict::FalseAlarm, falseAlarmCause: 'spider_web', id: 3);
        $day->recordFrames(['terrace/day.jpg'], 8, 4);
        $night = CameraEventFactory::described('The empty terrace at night.', '2026-11-05 23:00:00', verdict: Verdict::FalseAlarm, falseAlarmCause: 'nothing', id: 4);
        $night->recordFrames(['terrace/night.jpg'], 8, 4);
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(Argument::withEntry('falseAlarm', true), ['startedAt' => 'desc'], Argument::any())->willReturn([$event, $web, $day, $night]);
        $frames = $this->prophesize(FrameStorageInterface::class);
        $frames->isMonochrome(Argument::any())->willReturn(true);
        $frames->isMonochrome('terrace/day.jpg')->willReturn(false);

        $baseline = new SceneBaselines($events->reveal(), $frames->reveal(), $this->settingsProvider())->forEvent($event);

        self::assertSame('terrace/night.jpg', $baseline?->framePath, 'Not the event itself, no spider web, and no day view for a night event.');
        self::assertSame($night->getStartedAt(), $baseline->recordedAt);
    }

    public function testCanBeSwitchedOff(): void
    {
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(Argument::cetera())->shouldNotBeCalled();

        $baselines = new SceneBaselines($events->reveal(), $this->prophesize(FrameStorageInterface::class)->reveal(), $this->settingsProvider(['ai' => ['scene_baseline' => false]]));

        self::assertNull($baselines->forEvent(CameraEventFactory::withFrames()));
    }
}
