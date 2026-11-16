<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation;

use DateTimeImmutable;

final readonly class Recording
{
    public function __construct(
        public string $path,
        public int $startedAtMs,
    ) {
    }

    public function offsetSeconds(DateTimeImmutable $at): float
    {
        return ((int) $at->format('Uv') - $this->startedAtMs) / 1000;
    }
}
