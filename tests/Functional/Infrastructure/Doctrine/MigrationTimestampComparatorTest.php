<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\MigrationTimestampComparator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationTimestampComparator::class)]
final class MigrationTimestampComparatorTest extends TestCase
{
    /** Migrations are not autoloaded, so their names are plain strings. */
    private const string CORE = 'DoctrineMigrations\\';

    private const string ADD_ON = 'CameraDiary\HomeAssistant\Migrations\\';

    public function testOrdersByTimestampAcrossNamespacesWithTheCoreFirst(): void
    {
        $versions = array_map(static fn (string $name): Version => new Version($name), [
            self::ADD_ON.'Version20261117100100',
            self::CORE.'Version20261117150000',
            self::ADD_ON.'Version20261110120709',
            self::CORE.'Version20261117100000',
            self::CORE.'Version20261110120709',
        ]);

        usort($versions, new MigrationTimestampComparator()->compare(...));

        self::assertSame([
            self::CORE.'Version20261110120709',
            self::ADD_ON.'Version20261110120709',
            self::CORE.'Version20261117100000',
            self::ADD_ON.'Version20261117100100',
            self::CORE.'Version20261117150000',
        ], array_map(strval(...), $versions));
    }
}
