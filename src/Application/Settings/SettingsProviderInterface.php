<?php

declare(strict_types=1);

namespace App\Application\Settings;

interface SettingsProviderInterface
{
    /**
     * @throws InvalidSettingsException
     */
    public function settings(): Settings;
}
