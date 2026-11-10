<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\QueryBuilder;
use SortDirection;

final class OrderBy
{
    /**
     * @param array<string, 'asc'|'desc'> $sortBy field => direction
     */
    public static function apply(QueryBuilder $queryBuilder, string $alias, array $sortBy): void
    {
        foreach ($sortBy as $field => $direction) {
            $queryBuilder->addOrderBy($alias.'.'.$field, 'asc' === $direction ? SortDirection::Ascending : SortDirection::Descending);
        }
    }
}
