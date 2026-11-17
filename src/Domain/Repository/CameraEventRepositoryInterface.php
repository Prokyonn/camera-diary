<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\CameraEventNotFoundException;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use DateTimeImmutable;

/**
 * @phpstan-type CameraEventFilters array{
 *     id?: int,
 *     ids?: list<int>,
 *     camera?: Camera,
 *     cameraSlug?: string,
 *     source?: string,
 *     externalId?: string,
 *     open?: bool,
 *     detectionType?: string,
 *     status?: EventStatus,
 *     startedAtOrAfter?: DateTimeImmutable,
 *     startedBefore?: DateTimeImmutable,
 *     startedAtOrBefore?: DateTimeImmutable,
 *     endedBefore?: DateTimeImmutable,
 *     endedAtOrAfter?: DateTimeImmutable,
 *     dueAt?: DateTimeImmutable,
 *     falseAlarm?: bool,
 *     framesRemoved?: bool,
 * }
 * @phpstan-type CameraEventSortBy array{startedAt?: 'asc'|'desc', id?: 'asc'|'desc'}
 *
 * Filter semantics: `dueAt` matches events without a retry time or with one at or before it; `falseAlarm: true`
 * matches events whose effective verdict (manual override, else the AI's) is a false alarm, `false` all others.
 */
interface CameraEventRepositoryInterface
{
    public function add(CameraEvent $event): void;

    public function remove(CameraEvent $event): void;

    /**
     * @param CameraEventFilters $filters
     * @param CameraEventSortBy  $sortBy
     *
     * @return list<CameraEvent>
     */
    public function findBy(array $filters = [], array $sortBy = [], ?int $limit = null): array;

    /**
     * @param CameraEventFilters $filters
     * @param CameraEventSortBy  $sortBy
     */
    public function findOneBy(array $filters, array $sortBy = []): ?CameraEvent;

    /**
     * @param CameraEventFilters $filters
     *
     * @throws CameraEventNotFoundException
     */
    public function getOneBy(array $filters): CameraEvent;

    /**
     * @param CameraEventFilters $filters
     */
    public function countBy(array $filters = []): int;
}
