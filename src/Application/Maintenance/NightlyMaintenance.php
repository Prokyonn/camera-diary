<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use Psr\Log\LoggerInterface;

final readonly class NightlyMaintenance
{
    public function __construct(
        private FalseAlarmFrameRetention $frameRetention,
        private DatabaseBackupInterface $databaseBackup,
        private LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $removed = $this->frameRetention->run();
        $backup = $this->databaseBackup->run();

        $this->logger->info('Nightly maintenance: removed frames of {removed} false alarms, backup {backup}', [
            'removed' => $removed,
            'backup' => $backup ?? 'already done today',
        ]);
    }
}
