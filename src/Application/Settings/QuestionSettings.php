<?php

declare(strict_types=1);

namespace App\Application\Settings;

final readonly class QuestionSettings
{
    public function __construct(
        public int $searchResults,
        public int $answerEvents,
        public int $framesPerEvent,
    ) {
    }
}
