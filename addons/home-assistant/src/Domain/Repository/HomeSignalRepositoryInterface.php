<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Domain\Repository;

use CameraEvents\HomeAssistant\Domain\Model\HomeSignal;
use DateTimeImmutable;

/**
 * @phpstan-type HomeSignalFilters array{
 *     changedAtOrAfter?: DateTimeImmutable,
 *     changedAtOrBefore?: DateTimeImmutable,
 * }
 * @phpstan-type HomeSignalSortBy array{changedAt?: 'asc'|'desc'}
 */
interface HomeSignalRepositoryInterface
{
    public function add(HomeSignal $signal): void;

    /**
     * @param HomeSignalFilters $filters
     * @param HomeSignalSortBy  $sortBy
     *
     * @return list<HomeSignal>
     */
    public function findBy(array $filters = [], array $sortBy = []): array;
}
