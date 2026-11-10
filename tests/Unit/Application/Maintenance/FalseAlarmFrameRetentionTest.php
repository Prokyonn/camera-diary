<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Maintenance;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Maintenance\FalseAlarmFrameRetention;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Clock\MockClock;

#[CoversClass(FalseAlarmFrameRetention::class)]
final class FalseAlarmFrameRetentionTest extends TestCase
{
    use ProphecyTrait;

    public function testRemovesFramesOfFalseAlarmsOlderThanThirtyDays(): void
    {
        $web = CameraEventFactory::described('A dew-covered web thread swings across the lens.', '2026-11-06 17:36:00', verdict: Verdict::FalseAlarm, id: 9);
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy([
            'falseAlarm' => true,
            'framesRemoved' => false,
            'startedBefore' => CameraEventFactory::utc('2026-11-08 03:30:00'),
        ])->willReturn([$web]);
        $storage = $this->prophesize(FrameStorageInterface::class);
        $storage->remove(['terrace/frame-1793986560-0.jpg'])->shouldBeCalledOnce();
        $indexer = $this->prophesize(EventIndexerInterface::class);
        $indexer->index($web)->shouldBeCalledOnce();
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $unitOfWork->flush()->shouldBeCalledOnce();

        $retention = new FalseAlarmFrameRetention($events->reveal(), $storage->reveal(), $indexer->reveal(), $unitOfWork->reveal(), new MockClock('2026-12-08 03:30:00', 'UTC'));

        self::assertSame(1, $retention->run());
        self::assertSame([], $web->getFramePaths());
        self::assertInstanceOf(DateTimeImmutable::class, $web->getFramesRemovedAt());
    }
}
