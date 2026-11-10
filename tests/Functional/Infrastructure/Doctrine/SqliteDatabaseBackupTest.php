<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Doctrine;

use App\Application\Clock\HomeTimeZone;
use App\Infrastructure\Doctrine\SqliteDatabaseBackup;
use App\Tests\Support\ResetsStorage;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SqliteDatabaseBackup::class)]
final class SqliteDatabaseBackupTest extends KernelTestCase
{
    use ResetsStorage;

    private string $backupDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->backupDir = sys_get_temp_dir().'/camera-events-backups-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->backupDir);
        parent::tearDown();
    }

    public function testWritesOneBackupPerDayAndKeepsFourteen(): void
    {
        $filesystem = new Filesystem();
        for ($day = 1; $day <= 14; ++$day) {
            $filesystem->dumpFile(\sprintf('%s/events-2026-10-%02d.sqlite', $this->backupDir, $day), 'old');
        }

        $backup = new SqliteDatabaseBackup(
            self::getContainer()->get(Connection::class),
            new MockClock('2026-11-08 02:30:00', 'UTC'),
            new HomeTimeZone('Europe/Vienna'),
            $this->backupDir,
        );

        self::assertSame($this->backupDir.'/events-2026-11-08.sqlite', $backup->run());
        self::assertNull($backup->run(), 'A second run on the same day does nothing.');
        self::assertFileExists($this->backupDir.'/events-2026-11-08.sqlite');
        self::assertFileDoesNotExist($this->backupDir.'/events-2026-10-01.sqlite');
        $backups = glob($this->backupDir.'/events-*.sqlite');
        self::assertIsArray($backups);
        self::assertCount(14, $backups);
    }
}
