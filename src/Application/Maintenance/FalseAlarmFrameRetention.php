<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * False-alarm frames go after 30 days; the text of the event stays.
 */
final readonly class FalseAlarmFrameRetention
{
    public const int KEEP_DAYS = 30;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private FrameStorageInterface $frameStorage,
        private EventIndexerInterface $indexer,
        private UnitOfWorkInterface $unitOfWork,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the number of events whose frames were removed
     */
    public function run(): int
    {
        $now = $this->clock->now();
        $events = $this->events->findBy([
            'falseAlarm' => true,
            'framesRemoved' => false,
            'startedBefore' => $now->modify(\sprintf('-%d days', self::KEEP_DAYS)),
        ]);

        foreach ($events as $event) {
            $this->frameStorage->remove($event->removeFrames($now));
        }

        $this->unitOfWork->flush();
        foreach ($events as $event) {
            $this->indexer->index($event);
        }

        return \count($events);
    }
}
