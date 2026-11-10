<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\CameraEvent;

interface AnswerWriterInterface
{
    public const int MAX_EVENTS = 6;

    /**
     * Answers $question from the first MAX_EVENTS of $events and their frames.
     *
     * @param list<CameraEvent> $events best match first
     *
     * @throws AiRequestFailedException
     */
    public function answer(string $question, array $events): Answer;
}
