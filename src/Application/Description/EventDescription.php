<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\Side;
use App\Domain\Model\Verdict;

final readonly class EventDescription
{
    /**
     * @param ?string $falseAlarmCause a name from the false-alarm causes vocabulary; null for real events
     * @param string  $subject         a name from the subjects vocabulary
     */
    public function __construct(
        public Verdict $verdict,
        public ?string $falseAlarmCause,
        public Side $side,
        public string $subject,
        public string $summary,
        public bool $unusual,
        public string $model,
    ) {
    }
}
