<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\AskedQuestion;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\AskedQuestionRepositoryInterface;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Keeps the questions and their answers, so they can be read again without asking the AI.
 */
final readonly class QuestionHistory
{
    public function __construct(
        private AskedQuestionRepositoryInterface $questions,
        private CameraEventRepositoryInterface $events,
        private UnitOfWorkInterface $unitOfWork,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $question, QuestionResult $result): AskedQuestion
    {
        $asked = new AskedQuestion(
            $question,
            $result->answer,
            array_map(static fn (CameraEvent $event): int => $event->getId(), $result->events),
            $this->clock->now(),
        );
        $this->questions->add($asked);
        $this->unitOfWork->flush();

        return $asked;
    }

    /**
     * @return list<AskedQuestion> newest first
     */
    public function recent(int $limit): array
    {
        return $this->questions->findBy([], ['askedAt' => 'desc', 'id' => 'desc'], $limit);
    }

    /**
     * The stored answer with its events in their original order; events deleted since are left out.
     */
    public function result(AskedQuestion $question): QuestionResult
    {
        $ids = $question->getEventIds();
        $found = [] === $ids ? [] : $this->events->findBy(['ids' => $ids]);
        $byId = [];
        foreach ($found as $event) {
            $byId[$event->getId()] = $event;
        }

        $events = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $events[] = $byId[$id];
            }
        }

        return new QuestionResult($question->getAnswer(), $events);
    }
}
