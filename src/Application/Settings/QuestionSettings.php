<?php

declare(strict_types=1);

namespace App\Application\Settings;

final readonly class QuestionSettings
{
    /**
     * @param int $searchResults keyword matches for a question without a time range, and events shown with an answer
     * @param int $answerEvents  events whose frames the AI gets
     * @param int $contextEvents events of a time range the AI reads as text instead of keyword matches
     */
    public function __construct(
        public int $searchResults,
        public int $answerEvents,
        public int $framesPerEvent,
        public int $contextEvents,
    ) {
    }
}
