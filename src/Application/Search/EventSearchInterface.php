<?php

declare(strict_types=1);

namespace App\Application\Search;

interface EventSearchInterface
{
    public function search(SearchCriteria $criteria): SearchResult;
}
