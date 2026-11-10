<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

interface DatabaseBackupInterface
{
    /**
     * Writes today's backup and drops old ones.
     *
     * @return string|null the new backup file, null if today's backup already exists
     */
    public function run(): ?string;
}
