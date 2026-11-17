<?php

declare(strict_types=1);

namespace App\Application\Settings;

final readonly class AiSettings
{
    /**
     * @param list<CallPool> $callPools
     * @param int            $referencePhotos reference photos per known individual the AI gets with an event
     */
    public function __construct(
        public int $dailyCallLimit,
        public array $callPools,
        public string $homeContext,
        public string $unusualRule,
        public int $referencePhotos,
    ) {
    }

    /**
     * The first pool that holds one of the detection types; null when only the daily limit applies.
     *
     * @param list<string> $detectionTypes
     */
    public function callPoolFor(array $detectionTypes): ?CallPool
    {
        foreach ($this->callPools as $pool) {
            if ([] !== array_intersect($pool->detectionTypes, $detectionTypes)) {
                return $pool;
            }
        }

        return null;
    }
}
