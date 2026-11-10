<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Domain\Model\DetectionType;
use DateTimeImmutable;

final class TimelineFilter
{
    public function __construct(
        public ?DateTimeImmutable $day = null,
        public ?string $camera = null,
        public ?DetectionType $type = null,
        public bool $falseAlarms = false,
    ) {
    }
}
