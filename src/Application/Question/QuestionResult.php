<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Domain\Model\CameraEvent;

final readonly class QuestionResult
{
    /**
     * @param list<CameraEvent> $events cited events first, then the other matches
     */
    public function __construct(
        public string $answer,
        public array $events,
    ) {
    }
}
