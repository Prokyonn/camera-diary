<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use InvalidArgumentException;

final class FilterKeys
{
    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $allowed
     */
    public static function assertAllowed(array $filters, array $allowed): void
    {
        $unknown = array_diff(array_keys($filters), $allowed);
        if ([] !== $unknown) {
            throw new InvalidArgumentException(\sprintf('Unknown filter keys "%s"; allowed are "%s".', implode('", "', $unknown), implode('", "', $allowed)));
        }
    }
}
