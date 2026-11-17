<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * One entry of a vocabulary: the stored name, a label for people, and an optional hint for the AI.
 */
final readonly class Term
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $description = null,
    ) {
    }
}
