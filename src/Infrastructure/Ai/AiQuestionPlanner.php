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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AiQuestionPlanner implements QuestionPlannerInterface
{
    public function __construct(
        private StructuredClientInterface $client,
        private HomeTimeZone $homeTimeZone,
        private HomeContext $homeContext,
        private ClockInterface $clock,
        private SettingsProviderInterface $settings,
        private LoggerInterface $logger,
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
            Resolve relative times ("last night", "on Saturday") into a range. Night means 18:00 to 08:00. "This week", "lately" and "recently" mean the last seven days up to now.
            For a group of subjects ("animals", "wild animals", "visitors"), list every subject that belongs to it.
            {$notes}
            PROMPT), [$question]), SearchPlanOutput::class);
        $this->logger->info('Search plan for "{question}": {plan}', ['question' => $question, 'plan' => json_encode($plan)]);

        $settings = $this->settings->settings();

        return new SearchCriteria(
            text: $plan->keywords,
            cameras: array_values(array_intersect($plan->cameras, $this->homeContext->cameraSlugs())),
            from: $this->homeTimeZone->parseLocal($plan->from),
            to: $this->homeTimeZone->parseLocal($plan->to),
            side: $plan->side->side(),
            includeFalseAlarms: $plan->includeFalseAlarms,
            limit: $limit,
            subjects: $this->known($plan->subjects, array_combine($settings->subjects->names(), array_map($settings->subjects->label(...), $settings->subjects->names()))),
            individuals: $this->known($plan->individuals, array_combine(
                array_map(static fn (Individual $individual): string => $individual->name, $settings->individuals),
                array_map(static fn (Individual $individual): string => $individual->label, $settings->individuals),
            )),
        );
    }

    /**
     * Models sometimes answer with a label ("Luna") instead of the name ("luna").
     *
     * @param list<string>          $values
     * @param array<string, string> $labelsByName
     *
     * @return list<string> the names the values stand for, unknown ones dropped
     */
    private function known(array $values, array $labelsByName): array
    {
        $names = [];
        foreach ($labelsByName as $name => $label) {
            $names[mb_strtolower($name)] = $name;
            $names[mb_strtolower($label)] = $name;
        }

        $known = [];
        foreach ($values as $value) {
            $name = $names[mb_strtolower(trim($value))] ?? null;
            if (null !== $name && !\in_array($name, $known, true)) {
                $known[] = $name;
            }
        }

        return $known;
    }
}
