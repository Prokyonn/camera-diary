<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * A share of the daily AI calls for events with certain detection types, e.g. at most 75 for animals.
 */
final readonly class CallPool
{
    /**
     * @param list<string> $detectionTypes
     */
    public function __construct(
        public string $name,
        public array $detectionTypes,
        public int $dailyCallLimit,
    ) {
    }

    public function label(): string
    {
        return Vocabulary::humanize($this->name);
    }
}
