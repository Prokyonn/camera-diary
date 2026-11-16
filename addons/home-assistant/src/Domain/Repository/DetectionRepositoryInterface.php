<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Repository;

use CameraDiary\HomeAssistant\Domain\Model\Detection;
use DateTimeImmutable;

/**
 * @phpstan-type DetectionFilters array{
 *     detectedAtOrAfter?: DateTimeImmutable,
 *     detectedAtOrBefore?: DateTimeImmutable,
 *     unassigned?: bool,
 * }
 * @phpstan-type DetectionSortBy array{detectedAt?: 'asc'|'desc'}
 */
interface DetectionRepositoryInterface
{
    public function add(Detection $detection): void;

    /**
     * @param DetectionFilters $filters
     * @param DetectionSortBy  $sortBy
     *
     * @return list<Detection>
     */
    public function findBy(array $filters = [], array $sortBy = []): array;
}
