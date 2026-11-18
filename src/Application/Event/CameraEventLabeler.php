<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use Psr\Clock\ClockInterface;

/**
 * Saves the owner's correction or confirmation and makes search see it at once.
 */
final readonly class CameraEventLabeler
{
    public function __construct(
        private UnitOfWorkInterface $unitOfWork,
        private EventIndexerInterface $indexer,
        private ClockInterface $clock,
    ) {
    }

    public function relabel(CameraEvent $event, ?string $label, ?Verdict $verdictOverride, ?string $individualOverride): void
    {
        $event->relabel($label, $verdictOverride, $individualOverride);
        $this->saveReviewed($event);
    }

    /**
     * The AI's answer is right as it is.
     */
    public function confirm(CameraEvent $event): void
    {
        $this->saveReviewed($event);
    }

    private function saveReviewed(CameraEvent $event): void
    {
        $event->markReviewed($this->clock->now());
        $this->unitOfWork->flush();
        $this->indexer->index($event);
    }
}
