<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Settings;

/**
 * A context entity whose state changes become hints, e.g. a cat flap or a door lock.
 */
final readonly class SignalMapping
{
    /**
     * @param array<string, string> $states        state => hint text; other states are ignored
     * @param string|null           $timeAttribute attribute with the real time of the change, when the state lags behind
     */
    public function __construct(
        public string $entityId,
        public array $states,
        public ?string $timeAttribute = null,
    ) {
    }

    public function describe(string $state): ?string
    {
        return $this->states[$state] ?? null;
    }
}
