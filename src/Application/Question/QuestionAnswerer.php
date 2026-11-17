<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use Psr\Log\LoggerInterface;

final readonly class QuestionAnswerer
{
    public function __construct(
        private QuestionPlannerInterface $planner,
        private AnswerWriterInterface $writer,
        private EventSearchInterface $search,
        private AiCallBudgetInterface $budget,
        private SettingsProviderInterface $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function answer(string $question): QuestionResult
    {
        $events = $this->search->search($this->criteria($question));

        if ([] === $events) {
            return new QuestionResult('No matching events found.', []);
        }

        if (!$this->budget->tryConsume()) {
            return new QuestionResult('The daily AI limit is reached, so here are the matching events without an answer.', $events);
        }

        try {
            $answer = $this->writer->answer($question, $events);
        } catch (AiRequestFailedException $exception) {
            $this->logger->warning('The AI could not answer: {message}', ['message' => $exception->getMessage()]);

            return new QuestionResult('The AI could not answer right now, so here are the matching events.', $events);
        }

        return new QuestionResult($answer->text, $this->citedFirst($events, $answer->eventIds));
    }

    private function criteria(string $question): SearchCriteria
    {
        if ($this->budget->tryConsume()) {
            try {
                return $this->planner->plan($question, $this->settings->settings()->questions->searchResults);
            } catch (AiRequestFailedException $exception) {
                $this->logger->warning('The AI could not plan the search: {message}', ['message' => $exception->getMessage()]);
            }
        }

        return new SearchCriteria(text: $question, limit: $this->settings->settings()->questions->searchResults);
    }

    /**
     * @param list<CameraEvent> $events
     * @param list<int>         $citedIds
     *
     * @return list<CameraEvent>
     */
    private function citedFirst(array $events, array $citedIds): array
    {
        $remaining = [];
        foreach ($events as $event) {
            $remaining[$event->getId()] = $event;
        }

        $cited = [];
        foreach ($citedIds as $id) {
            if (isset($remaining[$id])) {
                $cited[] = $remaining[$id];
                unset($remaining[$id]);
            }
        }

        return [...$cited, ...array_values($remaining)];
    }
}
