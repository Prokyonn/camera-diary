<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Import;

final readonly class ImportReport
{
    public function __construct(
        public ImportResult $import,
        public int $newEvents,
        public int $closedEvents,
    ) {
    }

    public function summary(): string
    {
        return \sprintf(
            'imported %d detections and %d signals, %d new and %d closed events',
            $this->import->detections,
            $this->import->signals,
            $this->newEvents,
            $this->closedEvents,
        );
    }
}
