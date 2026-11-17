<?php

declare(strict_types=1);

namespace App\Application\Individual;

/**
 * A reference photo of a known individual: from the settings, or added in the app (then it has a stored id).
 */
final readonly class ReferencePhoto
{
    public function __construct(
        public string $path,
        public ?string $storedId = null,
    ) {
    }

    public function isStored(): bool
    {
        return null !== $this->storedId;
    }
}
