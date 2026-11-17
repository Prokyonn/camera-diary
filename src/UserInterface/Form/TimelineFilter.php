<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use DateTimeImmutable;

final class TimelineFilter
{
    public function __construct(
        public ?DateTimeImmutable $day = null,
        public ?string $camera = null,
        public ?string $type = null,
        public bool $falseAlarms = false,
    ) {
    }
}
