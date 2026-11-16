<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Domain\Repository;

use CameraEvents\HomeAssistant\Domain\Model\PushHandover;
use DateTimeImmutable;

/**
 * @phpstan-type PushHandoverFilters array{
 *     camera?: string,
 *     tag?: string,
 *     pushedAtOrAfter?: DateTimeImmutable,
 *     pushedAtOrBefore?: DateTimeImmutable,
 * }
 * @phpstan-type PushHandoverSortBy array{pushedAt?: 'asc'|'desc'}
 */
interface PushHandoverRepositoryInterface
{
    public function add(PushHandover $handover): void;

    /**
     * @param PushHandoverFilters $filters
     * @param PushHandoverSortBy  $sortBy
     *
     * @return list<PushHandover>
     */
    public function findBy(array $filters = [], array $sortBy = []): array;
}
