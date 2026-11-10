<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\History;

use DateTimeImmutable;

final readonly class StateChange
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $entityId,
        public string $state,
        public DateTimeImmutable $changedAt,
        public array $attributes = [],
    ) {
    }
}
