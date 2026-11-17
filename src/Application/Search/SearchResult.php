<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Model\CameraEvent;

final readonly class SearchResult
{
    /**
     * @param list<CameraEvent> $events best match first; without text, newest first; at most the criteria's limit
     * @param int               $total  all events that match, also beyond the limit
     */
    public function __construct(
        public array $events,
        public int $total,
    ) {
    }
}
