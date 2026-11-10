<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Import;

final readonly class ImportResult
{
    public function __construct(
        public int $detections = 0,
        public int $signals = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self($this->detections + $other->detections, $this->signals + $other->signals);
    }
}
