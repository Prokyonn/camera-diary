<?php

declare(strict_types=1);

namespace App\Application\Ingest;

use App\Domain\Model\DetectionType;
use DateTimeImmutable;

/**
 * An event sent from outside: a short video or up to three images, plus what is known about it.
 */
final readonly class IngestRequest
{
    public const int MAX_IMAGES = 3;

    /**
     * @param list<DetectionType> $detectionTypes
     * @param list<string>        $hints
     * @param list<string>        $imagePaths
     */
    public function __construct(
        public string $cameraSlug,
        public DateTimeImmutable $startedAt,
        public ?string $videoPath = null,
        public array $imagePaths = [],
        public array $detectionTypes = [],
        public array $hints = [],
        public ?DateTimeImmutable $endedAt = null,
        public string $source = 'api',
        public ?string $externalId = null,
        public ?string $cameraName = null,
    ) {
    }
}
