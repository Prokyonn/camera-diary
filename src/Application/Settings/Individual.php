<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Domain\Model\CameraEvent;

/**
 * A known individual, e.g. the household's cat: the AI compares an event's subject with its reference photos.
 */
final readonly class Individual
{
    /** What the AI answers for a subject that is none of the known individuals; reserved. */
    public const string UNKNOWN = CameraEvent::NO_KNOWN_INDIVIDUAL;

    /**
     * @param string       $subject        a name from the subjects vocabulary
     * @param list<string> $photos         absolute paths of the reference photos in the settings
     * @param list<string> $detectionTypes the events whose AI request gets the photos; empty for all
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $subject,
        public ?string $description,
        public array $photos,
        public array $detectionTypes,
    ) {
    }
}
