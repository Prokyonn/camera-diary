<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Application\Clock\HomeTimeZone;
use App\Application\Maintenance\DatabaseBackupInterface;
use App\Application\Settings\SettingsProviderInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Writes a consistent copy of the SQLite database with VACUUM INTO and keeps the newest 14.
 */
final readonly class SqliteDatabaseBackup implements DatabaseBackupInterface
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
        #[Autowire('%app.data_dir%/backups')]
        private string $backupDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function run(): ?string
    {
        $this->filesystem->mkdir($this->backupDir);
        $target = \sprintf('%s/events-%s.sqlite', $this->backupDir, $this->homeTimeZone->toLocal($this->clock->now())->format('Y-m-d'));

        if ($this->filesystem->exists($target)) {
            return null;
        }

        $this->connection->executeStatement('VACUUM INTO '.$this->connection->quote($target));
        $this->removeOldBackups();

        return $target;
    }

    private function removeOldBackups(): void
    {
        $backups = iterator_to_array(
            new Finder()->files()->in($this->backupDir)->name('events-*.sqlite')->sortByName()->reverseSorting(),
            false,
        );

        $this->filesystem->remove(\array_slice($backups, $this->settings->settings()->maintenance->backupsKept));
    }
}
