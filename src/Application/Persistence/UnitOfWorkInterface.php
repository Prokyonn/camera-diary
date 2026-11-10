<?php

declare(strict_types=1);

namespace App\Application\Persistence;

/**
 * Writes pending changes of the domain models to storage.
 */
interface UnitOfWorkInterface
{
    public function flush(): void;
}
