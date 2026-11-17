<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Settings;

/**
 * Home Assistant hands its camera pushes over through an input_text; the AI verdict replaces them.
 */
final readonly class PushSettings
{
    /**
     * @param array<string, string>      $muteActions action id => button title, kept on pushes that had them
     * @param non-empty-list<float|null> $frames      per frame of an event: null for the snapshot, else the second in the clip
     */
    public function __construct(
        public string $handoverEntity,
        public string $notifyService,
        public ?string $url,
        public string $detailsTitle,
        public string $loudInterruptionLevel,
        public array $muteActions,
        public int $maxAgeSeconds,
        public array $frames,
    ) {
    }
}
