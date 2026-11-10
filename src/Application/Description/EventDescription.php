<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\FalseAlarmCause;
use App\Domain\Model\Side;
use App\Domain\Model\Subject;
use App\Domain\Model\Verdict;

final readonly class EventDescription
{
    public function __construct(
        public Verdict $verdict,
        public FalseAlarmCause $falseAlarmCause,
        public Side $side,
        public Subject $subject,
        public string $summary,
        public string $model,
    ) {
    }
}
