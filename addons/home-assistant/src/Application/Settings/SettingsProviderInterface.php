<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Settings;

interface SettingsProviderInterface
{
    /**
     * Empty settings when the add-on is not configured.
     *
     * @throws InvalidSettingsException
     */
    public function settings(): HomeAssistantSettings;
}
