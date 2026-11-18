<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\DayDigest;

/**
 * @phpstan-type DayDigestFilters array{day?: string}
 */
interface DayDigestRepositoryInterface
{
    public function add(DayDigest $digest): void;

    /**
     * @param DayDigestFilters $filters
     */
    public function findOneBy(array $filters): ?DayDigest;
}
