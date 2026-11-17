<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;

/**
 * Saves a manual correction and makes search see it at once.
 */
final readonly class CameraEventLabeler
{
    public function __construct(
        private UnitOfWorkInterface $unitOfWork,
        private EventIndexerInterface $indexer,
    ) {
    }

    public function relabel(CameraEvent $event, ?string $label, ?Verdict $verdictOverride, ?string $individualOverride): void
    {
        $event->relabel($label, $verdictOverride, $individualOverride);
        $this->unitOfWork->flush();
        $this->indexer->index($event);
    }
}
