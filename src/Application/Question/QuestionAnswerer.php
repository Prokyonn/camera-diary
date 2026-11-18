<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Application\Search\SearchResult;
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
        $criteria = $this->criteria($question);
        $found = $this->events($criteria);
        $broadened = [] === $found->events && !$criteria->isBroad();
        if ($broadened) {
            $found = $this->events($criteria->broadened());
        }

        $events = $found->events;

        if ([] === $events) {
            return new QuestionResult('No matching events found.', []);
        }

        if (!$this->budget->tryConsume()) {
            return new QuestionResult('The daily AI limit is reached, so here are the matching events without an answer.', $this->shown($events));
        }

        try {
            $answer = $this->writer->answer($question, $events, $found->total, $broadened);
        } catch (AiRequestFailedException $exception) {
            $this->logger->warning('The AI could not answer: {message}', ['message' => $exception->getMessage()]);

            return new QuestionResult('The AI could not answer right now, so here are the matching events.', $this->shown($events));
        }

        return new QuestionResult($answer->text, $this->shown($this->citedFirst($events, $answer->eventIds)));
    }

    /**
     * For a time range with few enough events, all of them, the keyword matches first, so the AI reads everything and
     * wording cannot hide an event. Otherwise the best keyword matches.
     */
    private function events(SearchCriteria $criteria): SearchResult
    {
        $questions = $this->settings->settings()->questions;
        if ($criteria->hasTimeRange()) {
            $inRange = $this->search->search($criteria->withText('')->withLimit($questions->contextEvents));
            if ($inRange->total <= $questions->contextEvents) {
                $matches = '' === trim($criteria->text) ? [] : $this->search->search($criteria->withLimit($questions->answerEvents))->events;

                return new SearchResult($this->citedFirst($inRange->events, array_map(static fn (CameraEvent $event): int => $event->getId(), $matches)), $inRange->total);
            }
        }

        return $this->search->search($criteria);
    }

    /**
     * @param list<CameraEvent> $events
     *
     * @return list<CameraEvent> the events shown with the answer
     */
    private function shown(array $events): array
    {
        return \array_slice($events, 0, $this->settings->settings()->questions->searchResults);
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
     * @param list<?int>        $citedIds
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
