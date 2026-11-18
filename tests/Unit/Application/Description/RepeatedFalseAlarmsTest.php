<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Description\RepeatedFalseAlarms;
use App\Application\Description\SceneChange;
use App\Application\Frame\BrightnessGrid;
use App\Application\Frame\FrameStorageInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

#[CoversClass(RepeatedFalseAlarms::class)]
final class RepeatedFalseAlarmsTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    /** @var ObjectProphecy<FrameStorageInterface> */
    private ObjectProphecy $frames;

    protected function setUp(): void
    {
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->frames = $this->prophesize(FrameStorageInterface::class);
        $this->frames->brightnessGrid(Argument::any(), Argument::any())->willReturn($this->grid());
    }

    public function testAnEventLikeTheOngoingFalseAlarmsRepeatsTheLatestChecked(): void
    {
        $latest = $this->spider('03:40:00', 3);
        $this->recent($latest, $this->spider('03:30:00', 2), $this->spider('03:20:00', 1));

        self::assertSame($latest, $this->repeats()->checkedOriginalOf($this->event('03:50:00')));
    }

    public function testLooksForTheStreakOnTheSameCameraInTheHourBefore(): void
    {
        $event = $this->event('03:50:00');
        $this->events->findBy(
            [
                'camera' => $event->getCamera(),
                'status' => EventStatus::Described,
                'startedAtOrAfter' => CameraEventFactory::utc('2026-11-18 02:50:00'),
                'startedBefore' => $event->getStartedAt(),
            ],
            ['startedAt' => 'desc'],
        )->willReturn([$this->spider('03:40:00', 3), $this->spider('03:30:00', 2), $this->spider('03:20:00', 1)])->shouldBeCalledOnce();

        self::assertInstanceOf(CameraEvent::class, $this->repeats()->checkedOriginalOf($event));
    }

    public function testTwoFalseAlarmsAreNoStreak(): void
    {
        $this->recent($this->spider('03:40:00', 3), $this->spider('03:30:00', 2));

        self::assertNull($this->repeats()->checkedOriginalOf($this->event('03:50:00')));
    }

    public function testARealEventOrAnotherCauseEndsTheStreak(): void
    {
        $cat = CameraEventFactory::described('A cat.', '2026-11-18 03:25:00', id: 4);
        $insect = CameraEventFactory::described('An insect.', '2026-11-18 03:15:00', verdict: Verdict::FalseAlarm, falseAlarmCause: 'insect', id: 5);
        $this->recent($this->spider('03:40:00', 3), $this->spider('03:30:00', 2), $cat, $this->spider('03:20:00', 1));
        self::assertNull($this->repeats()->checkedOriginalOf($this->event('03:50:00')));

        $this->recent($this->spider('03:40:00', 3), $this->spider('03:30:00', 2), $insect);
        self::assertNull($this->repeats()->checkedOriginalOf($this->event('03:50:00')));
    }

    public function testRepeatsCountForTheStreakButTheLatestCheckedIsTheOriginal(): void
    {
        $checked = $this->spider('03:20:00', 1);
        $first = $this->spider('03:30:00', 2);
        $first->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-18 03:31:00'));
        $second = $this->spider('03:40:00', 3);
        $second->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-18 03:41:00'));
        $this->recent($second, $first, $checked);

        self::assertSame($checked, $this->repeats()->checkedOriginalOf($this->event('03:45:00')));
    }

    public function testTheAiLooksAgainWhenTheLatestCheckIsTooOld(): void
    {
        $checked = $this->spider('03:00:00', 1);
        $first = $this->spider('03:20:00', 2);
        $first->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-18 03:21:00'));
        $second = $this->spider('03:30:00', 3);
        $second->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-18 03:31:00'));
        $this->recent($second, $first, $checked);

        self::assertNull($this->repeats()->checkedOriginalOf($this->event('03:31:00')), 'Thirty-one minutes after the last check.');
        self::assertSame($checked, $this->repeats(['ai' => ['repeat_recheck_minutes' => 0]])->checkedOriginalOf($this->event('03:31:00')), '0 means never.');
    }

    public function testFramesThatShowSomethingNewGoToTheAi(): void
    {
        $this->recent($this->spider('03:40:00', 3), $this->spider('03:30:00', 2), $this->spider('03:20:00', 1));
        $event = $this->event('03:50:00');
        $this->frames->brightnessGrid('garden/frame-'.$event->getStartedAt()->format('U').'-0.jpg', Argument::any())->willReturn($this->grid(cat: true));

        self::assertNull($this->repeats()->checkedOriginalOf($event));
    }

    public function testUnreadableFramesGoToTheAi(): void
    {
        $this->recent($this->spider('03:40:00', 3), $this->spider('03:30:00', 2), $this->spider('03:20:00', 1));
        $event = $this->event('03:50:00');
        $this->frames->brightnessGrid('garden/frame-'.$event->getStartedAt()->format('U').'-0.jpg', Argument::any())->willReturn(null);

        self::assertNull($this->repeats()->checkedOriginalOf($event));
    }

    public function testCanBeSwitchedOff(): void
    {
        $this->events->findBy(Argument::cetera())->shouldNotBeCalled();

        self::assertNull($this->repeats(['ai' => ['skip_repeats' => false]])->checkedOriginalOf($this->event('03:50:00')));
    }

    private function recent(CameraEvent ...$events): void
    {
        $this->events->findBy(Argument::cetera())->willReturn(array_values($events));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function repeats(array $settings = []): RepeatedFalseAlarms
    {
        return new RepeatedFalseAlarms($this->events->reveal(), $this->frames->reveal(), new SceneChange(), $this->settingsProvider($settings));
    }

    private function spider(string $time, int $id): CameraEvent
    {
        return CameraEventFactory::described('A spider web in front of the lens.', '2026-11-18 '.$time, 'garden', Verdict::FalseAlarm, 'nothing', falseAlarmCause: 'spider_web', id: $id);
    }

    private function event(string $time): CameraEvent
    {
        return CameraEventFactory::withFrames('2026-11-18 '.$time, 'garden', 99);
    }

    private function grid(bool $cat = false): BrightnessGrid
    {
        $catCell = $cat ? 6 * 16 + 14 : null;

        return new BrightnessGrid(16, array_map(static fn (int $index): float => $index === $catCell ? 20.0 : 100.0, range(0, 16 * 8 - 1)));
    }
}
