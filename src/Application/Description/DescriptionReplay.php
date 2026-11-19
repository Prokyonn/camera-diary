<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use Psr\Clock\ClockInterface;

/**
 * Stores a description recorded from an earlier AI run, so the demo works without an AI.
 */
final readonly class DescriptionReplay
{
    public function __construct(
        private UnitOfWorkInterface $unitOfWork,
        private EventIndexerInterface $indexer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool false when the event is already described
     */
    public function replay(CameraEvent $event, EventDescription $description): bool
    {
        if (EventStatus::Pending !== $event->getStatus() || $event->isOpen()) {
            return false;
        }

        $event->describe(
            $description->verdict,
            $description->falseAlarmCause,
            $description->side,
            $description->subject,
            $description->individual,
            $description->summary,
            $description->unusual,
            $description->confidence,
            $description->model,
            $this->clock->now(),
            $description->alsoSeen,
        );
        $this->unitOfWork->flush();
        $this->indexer->index($event);

        return true;
    }

    /**
     * @return bool false when the event is already described
     */
    public function replayRepeat(CameraEvent $event, CameraEvent $checked): bool
    {
        if (EventStatus::Pending !== $event->getStatus() || $event->isOpen()) {
            return false;
        }

        $event->describeAsRepeatOf($checked, $this->clock->now());
        $this->unitOfWork->flush();
        $this->indexer->index($event);

        return true;
    }
}
