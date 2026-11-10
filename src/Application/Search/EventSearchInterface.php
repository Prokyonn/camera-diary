<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Model\CameraEvent;

interface EventSearchInterface
{
    /**
     * @return list<CameraEvent> best match first; without text, newest first
     */
    public function search(SearchCriteria $criteria): array;
}
