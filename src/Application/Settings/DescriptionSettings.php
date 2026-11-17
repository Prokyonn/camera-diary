<?php

declare(strict_types=1);

namespace App\Application\Settings;

final readonly class DescriptionSettings
{
    /**
     * @param non-empty-list<int> $frameOffsets seconds after the start of an event at which a frame source cuts frames
     */
    public function __construct(
        public array $frameOffsets,
        public int $retryDelaySeconds,
        public int $maxAttempts,
        public int $footageGraceSeconds,
        public int $batchSize,
    ) {
    }
}
