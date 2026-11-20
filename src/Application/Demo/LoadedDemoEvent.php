<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Domain\Model\EventStatus;

final readonly class LoadedDemoEvent
{
    public function __construct(
        public string $key,
        public string $camera,
        public bool $created,
        public EventStatus $status,
    ) {
    }
}
