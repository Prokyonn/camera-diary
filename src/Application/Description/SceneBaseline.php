<?php

declare(strict_types=1);

namespace App\Application\Description;

use DateTimeImmutable;

/**
 * A frame of a camera's usual view with nothing happening, which the AI compares an event's frames with.
 */
final readonly class SceneBaseline
{
    public function __construct(
        public string $framePath,
        public DateTimeImmutable $recordedAt,
    ) {
    }
}
