<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Maintenance;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Maintenance\DatabaseBackupInterface;
use App\Application\Maintenance\FalseAlarmFrameRetention;
use App\Application\Maintenance\NightlyMaintenance;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(NightlyMaintenance::class)]
final class NightlyMaintenanceTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    public function testRemovesOldFalseAlarmFramesAndBacksUpTheDatabase(): void
    {
        $web = CameraEventFactory::described('A web thread.', '2026-11-06 17:36:00', verdict: Verdict::FalseAlarm, id: 9);
        $backup = $this->prophesize(DatabaseBackupInterface::class);
        $backup->run()->willReturn('/backups/2026-12-08.sqlite')->shouldBeCalledOnce();
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->info(Argument::type('string'), ['removed' => 1, 'backup' => '/backups/2026-12-08.sqlite'])->shouldBeCalledOnce();

        $this->maintenance([$web], $backup, $logger)->run();

        self::assertSame([], $web->getFramePaths());
    }

    public function testReportsABackupThatWasAlreadyDone(): void
    {
        $backup = $this->prophesize(DatabaseBackupInterface::class);
        $backup->run()->willReturn(null);
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->info(Argument::type('string'), ['removed' => 0, 'backup' => 'already done today'])->shouldBeCalledOnce();

        $this->maintenance([], $backup, $logger)->run();
    }

    /**
     * @param list<CameraEvent>                       $oldFalseAlarms
     * @param ObjectProphecy<DatabaseBackupInterface> $backup
     * @param ObjectProphecy<LoggerInterface>         $logger
     */
    private function maintenance(array $oldFalseAlarms, ObjectProphecy $backup, ObjectProphecy $logger): NightlyMaintenance
    {
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(Argument::cetera())->willReturn($oldFalseAlarms);
        $retention = new FalseAlarmFrameRetention(
            $events->reveal(),
            $this->prophesize(FrameStorageInterface::class)->reveal(),
            $this->prophesize(EventIndexerInterface::class)->reveal(),
            $this->prophesize(UnitOfWorkInterface::class)->reveal(),
            $this->settingsProvider(),
            new MockClock('2026-12-08 03:30:00', 'UTC'),
        );

        return new NightlyMaintenance($retention, $backup->reveal(), $logger->reveal());
    }
}
