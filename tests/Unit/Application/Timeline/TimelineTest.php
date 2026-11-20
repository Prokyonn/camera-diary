<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Timeline;

use App\Application\Clock\HomeTimeZone;
use App\Application\Timeline\Timeline;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

#[CoversClass(Timeline::class)]
final class TimelineTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    protected function setUp(): void
    {
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
    }

    public function testListsTheLocalDayWithoutFalseAlarms(): void
    {
        $cat = CameraEventFactory::described('A cat.', '2026-11-07 12:00:00');
        $this->events->findBy([
            'falseAlarm' => false,
            'startedAtOrAfter' => CameraEventFactory::utc('2026-11-06 23:00:00'),
            'startedBefore' => CameraEventFactory::utc('2026-11-07 23:00:00'),
        ], ['startedAt' => 'desc'])->willReturn([$cat])->shouldBeCalledOnce();
        $this->events->findOneBy(Argument::cetera())->shouldNotBeCalled();

        $day = $this->timeline()->day($this->localDay('2026-11-07'));

        self::assertSame([$cat], $day->events);
        self::assertNull($day->latestBefore);
    }

    public function testAnEmptyDayPointsToTheLatestEarlierEventWithTheSameFilters(): void
    {
        $earlier = CameraEventFactory::described('A fox.', '2026-11-04 03:00:00', 'garden');
        $this->events->findBy(Argument::cetera())->willReturn([]);
        $this->events->findOneBy([
            'cameraSlug' => 'garden',
            'detectionType' => 'animal',
            'startedBefore' => CameraEventFactory::utc('2026-11-06 23:00:00'),
        ], ['startedAt' => 'desc'])->willReturn($earlier)->shouldBeCalledOnce();

        $day = $this->timeline()->day($this->localDay('2026-11-07'), 'garden', 'animal', falseAlarms: true);

        self::assertSame([], $day->events);
        self::assertSame($earlier, $day->latestBefore);
    }

    private function timeline(): Timeline
    {
        return new Timeline($this->events->reveal(), new HomeTimeZone('Europe/Vienna'));
    }

    private function localDay(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('Europe/Vienna'));
    }
}
