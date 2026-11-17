<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Names of detection types, subjects and false-alarm causes: configured per installation and stored with the events.
 */
final class TermName
{
    public const string PATTERN = '[a-z][a-z0-9_]{0,31}';

    public static function isValid(string $name): bool
    {
        return 1 === preg_match('/^'.self::PATTERN.'$/', $name);
    }
}
