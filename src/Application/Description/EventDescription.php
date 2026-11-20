<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;

final readonly class EventDescription
{
    /** The false-alarm cause the AI names for a real event; reserved. */
    public const string NO_FALSE_ALARM = 'none';

    /**
     * @param ?string      $falseAlarmCause a name from the false-alarm causes vocabulary; null for real events
     * @param string       $subject         a name from the subjects vocabulary
     * @param ?string      $individual      the name of a known individual; null if the subject is none of them
     * @param list<string> $alsoSeen        other known individuals in the frames besides the subject
     */
    public function __construct(
        public Verdict $verdict,
        public ?string $falseAlarmCause,
        public Side $side,
        public string $subject,
        public ?string $individual,
        public string $summary,
        public bool $unusual,
        public Confidence $confidence,
        public string $model,
        public array $alsoSeen = [],
    ) {
    }
}
