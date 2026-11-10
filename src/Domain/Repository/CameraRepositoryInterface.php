<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\CameraNotFoundException;
use App\Domain\Model\Camera;

/**
 * @phpstan-type CameraFilters array{slug?: string}
 * @phpstan-type CameraSortBy array{name?: 'asc'|'desc'}
 */
interface CameraRepositoryInterface
{
    public function add(Camera $camera): void;

    /**
     * @param CameraFilters $filters
     * @param CameraSortBy  $sortBy
     *
     * @return list<Camera>
     */
    public function findBy(array $filters = [], array $sortBy = []): array;

    /**
     * @param CameraFilters $filters
     */
    public function findOneBy(array $filters): ?Camera;

    /**
     * @param CameraFilters $filters
     *
     * @throws CameraNotFoundException
     */
    public function getOneBy(array $filters): Camera;
}
