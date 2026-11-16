<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Settings;

/**
 * Home Assistant hands its camera pushes over through an input_text; the AI verdict replaces them.
 */
final readonly class PushSettings
{
    /**
     * @param array<string, string> $muteActions action id => button title, kept on pushes that had them
     */
    public function __construct(
        public string $handoverEntity,
        public string $notifyService,
        public ?string $url = null,
        public string $detailsTitle = 'Details',
        public string $loudInterruptionLevel = 'active',
        public array $muteActions = [],
    ) {
    }
}
