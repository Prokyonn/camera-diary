<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Domain\Repository;

use CameraEvents\HomeAssistant\Domain\Model\ImportCursor;

/**
 * @phpstan-type ImportCursorFilters array{name?: string}
 */
interface ImportCursorRepositoryInterface
{
    public function add(ImportCursor $cursor): void;

    /**
     * @param ImportCursorFilters $filters
     */
    public function findOneBy(array $filters): ?ImportCursor;
}
