<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Settings\SettingsProviderInterface;
use App\Tests\Factory\SettingsFactory;

/**
 * For tests with the ProphecyTrait: a settings provider with the defaults, or with the given settings file content.
 */
trait ProvidesSettings
{
    /**
     * @param array<string, mixed> $config
     */
    private function settingsProvider(array $config = []): SettingsProviderInterface
    {
        $provider = $this->prophesize(SettingsProviderInterface::class);
        $provider->settings()->willReturn(SettingsFactory::create($config));

        return $provider->reveal();
    }
}
