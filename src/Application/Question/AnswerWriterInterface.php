<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\CameraEvent;

interface AnswerWriterInterface
{
    /**
     * Answers $question from the top of $events (questions.answer_events in the settings) and their frames.
     *
     * @param list<CameraEvent> $events best match first
     *
     * @throws AiRequestFailedException
     */
    public function answer(string $question, array $events): Answer;
}
