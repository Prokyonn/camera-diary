<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * False-alarm frames go after 30 days; the text of the event stays.
 */
final readonly class FalseAlarmFrameRetention
{
    public function __construct(
        private CameraEventRepositoryInterface $events,
        private FrameStorageInterface $frameStorage,
        private EventIndexerInterface $indexer,
        private UnitOfWorkInterface $unitOfWork,
        private SettingsProviderInterface $settings,
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
            'startedBefore' => $now->modify(\sprintf('-%d days', $this->settings->settings()->maintenance->falseAlarmFrameDays)),
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
