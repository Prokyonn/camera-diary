<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\CameraEvent;

interface AnswerWriterInterface
{
    /**
     * Answers $question from $events as text, with frames of the first ones (questions.answer_events).
     *
     * @param list<CameraEvent> $events    best match first
     * @param int               $total     all events that match, $events may be fewer
     * @param bool              $broadened nothing matched the exact search, so $events are all of its time range
     *
     * @throws AiRequestFailedException
     */
    public function answer(string $question, array $events, int $total, bool $broadened): Answer;
}
