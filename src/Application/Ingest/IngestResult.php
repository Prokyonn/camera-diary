<?php

declare(strict_types=1);

namespace App\Application\Ingest;

use App\Domain\Model\CameraEvent;

final readonly class IngestResult
{
    public function __construct(
        public CameraEvent $event,
        public bool $created,
    ) {
    }
}
