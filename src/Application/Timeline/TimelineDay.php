<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Domain\Model\CameraEvent;

/**
 * One local day of the timeline with the chosen filters.
 */
final readonly class TimelineDay
{
    /**
     * @param list<CameraEvent> $events       newest first
     * @param ?CameraEvent      $latestBefore the latest earlier event with the same filters, when the day has none
     */
    public function __construct(
        public array $events,
        public ?CameraEvent $latestBefore,
    ) {
    }
}
