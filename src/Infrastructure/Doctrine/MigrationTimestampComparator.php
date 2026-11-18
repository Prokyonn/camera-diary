<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Orders the migrations of the core and the add-ons by the timestamp in their class name, not by namespace, so an
 * add-on migration that refers to a core table runs after the migration that creates it; on the same timestamp, the
 * core's runs first.
 */
final readonly class MigrationTimestampComparator implements Comparator
{
    private const string CORE_NAMESPACE = 'DoctrineMigrations\\';

    public function compare(Version $a, Version $b): int
    {
        return [$this->timestamp($a), $this->isAddOn($a), (string) $a] <=> [$this->timestamp($b), $this->isAddOn($b), (string) $b];
    }

    private function isAddOn(Version $version): bool
    {
        return !str_starts_with((string) $version, self::CORE_NAMESPACE);
    }

    private function timestamp(Version $version): string
    {
        return 1 === preg_match('/Version(\d+)$/', (string) $version, $matches) ? $matches[1] : (string) $version;
    }
}
