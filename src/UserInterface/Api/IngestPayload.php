<?php

declare(strict_types=1);

namespace App\UserInterface\Api;

use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\TermName;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form fields of POST /api/events; the media come as the files `video` or `images[]`.
 */
final class IngestPayload
{
    /**
     * @param list<string> $detections names from the detection types vocabulary
     * @param list<string> $hints
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^'.Camera::SLUG_PATTERN.'$/', message: 'Use lower-case letters, digits, "-" and "_", at most 32 characters.')]
        public string $camera = '',
        #[Assert\Length(max: 120)]
        public ?string $cameraName = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endedAt = null,
        #[Assert\Count(max: 10)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^'.TermName::PATTERN.'$/')])]
        public array $detections = [],
        #[Assert\Count(max: CameraEvent::MAX_HINTS)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: CameraEvent::MAX_HINT_LENGTH)])]
        public array $hints = [],
        #[Assert\Length(max: 120)]
        public ?string $externalId = null,
        #[Assert\Regex('/^[a-z0-9_-]{1,32}$/')]
        public string $source = 'api',
    ) {
    }
}
