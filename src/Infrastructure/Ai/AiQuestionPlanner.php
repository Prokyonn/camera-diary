<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Question\QuestionPlannerInterface;
use App\Application\Search\SearchCriteria;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use App\Infrastructure\Ai\Output\SearchPlanOutput;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Clock\ClockInterface;

final readonly class AiQuestionPlanner implements QuestionPlannerInterface
{
    public function __construct(
        private StructuredClientInterface $client,
        private HomeTimeZone $homeTimeZone,
        private HomeContext $homeContext,
        private ClockInterface $clock,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function plan(string $question, int $limit): SearchCriteria
    {
        $now = $this->homeTimeZone->toLocal($this->clock->now());
        $notes = $this->homeContext->notes();

        $plan = $this->client->request(new Prompt(trim(<<<PROMPT
            You turn questions about home camera events into a search. The events are stored with a subject, a known individual if recognised, an English description, a manual label and hints from the home.
            Use subjects and individuals for who or what the question is about (people, visitors or anyone: person); keywords only for further details.
            Cameras:
            {$this->homeContext->cameraList()}
            Now is {$now->format('l, j F Y, H:i')} in {$this->homeTimeZone->zone()->getName()}.
            Resolve relative times ("last night", "on Saturday") into a range. Night means 18:00 to 08:00.
            {$notes}
            PROMPT), [$question]), SearchPlanOutput::class);

        $settings = $this->settings->settings();

        return new SearchCriteria(
            text: $plan->keywords,
            cameras: array_values(array_intersect($plan->cameras, $this->homeContext->cameraSlugs())),
            from: $this->parseTime($plan->from),
            to: $this->parseTime($plan->to),
            side: $plan->side->side(),
            includeFalseAlarms: $plan->includeFalseAlarms,
            limit: $limit,
            subjects: array_values(array_intersect($plan->subjects, $settings->subjects->names())),
            individuals: array_values(array_intersect($plan->individuals, array_map(static fn (Individual $individual): string => $individual->name, $settings->individuals))),
        );
    }

    private function parseTime(string $value): ?DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->homeTimeZone->zone())->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
