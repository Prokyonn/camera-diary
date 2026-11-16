<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Repository;

use CameraDiary\HomeAssistant\Domain\Model\ImportCursor;

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
