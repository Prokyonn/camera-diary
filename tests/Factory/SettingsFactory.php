<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Application\Settings\Settings;
use App\Infrastructure\Settings\SettingsBuilder;

final class SettingsFactory
{
    /**
     * @param array<string, mixed> $config like the settings file; defaults for everything left out
     */
    public static function create(array $config = []): Settings
    {
        return new SettingsBuilder()->build($config);
    }
}
