<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Activity\ActivityReport;
use App\Application\Activity\CameraActivity;
use App\Application\Clock\HomeTimeZone;
use App\Application\Event\CameraEventLabeler;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * Exact counts, the known individuals, and corrections, for assistants that talk about the home's cameras.
 */
final readonly class HomeTools
{
    public function __construct(
        private ActivityReport $activityReport,
        private CameraEventRepositoryInterface $events,
        private CameraEventLabeler $labeler,
        private SettingsProviderInterface $settings,
        private EventPresenter $presenter,
        private TimeParser $timeParser,
        private HomeTimeZone $homeTimeZone,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     real_events: int,
     *     false_alarms: int,
     *     not_described_yet: int,
     *     real_events_by_who: array<string, int>,
     *     false_alarms_by_cause: array<string, int>,
     *     real_events_by_local_hour: array<int, int>,
     *     real_events_by_day: array<string, int>,
     *     cameras: list<array{slug: string, name: string, real_events: int, false_alarms: int, false_alarms_by_cause: array<string, int>}>,
     * }
     */
    #[McpTool(
        name: 'camera_activity',
        description: 'Exact counts of what the cameras saw in a time range: real events per subject or known individual ("Luna", "Other cat", "Person"), per local hour and per day; false alarms per cause; and per camera. Use it for "how often" and "how many" questions instead of counting search results.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function activity(
        #[Schema(description: 'Start, ISO 8601 (local time if no offset); empty for 7 days ago')]
        string $from = '',
        #[Schema(description: 'End, ISO 8601 (local time if no offset); empty for now')]
        string $to = '',
        #[Schema(description: 'Camera slug from list_cameras; empty for all cameras')]
        string $camera = '',
    ): array {
        $end = $this->timeParser->parse($to) ?? $this->clock->now();
        $start = $this->timeParser->parse($from) ?? $end->modify('-7 days');
        if ($start >= $end) {
            throw new InvalidArgumentException('"from" must be before "to".');
        }

        $activity = $this->activityReport->between($start, $end, '' === $camera ? null : $camera);

        return [
            'from' => $this->local($activity->from),
            'to' => $this->local($activity->to),
            'real_events' => $activity->real,
            'false_alarms' => $activity->falseAlarms,
            'not_described_yet' => $activity->undescribed,
            'real_events_by_who' => $activity->byWho,
            'false_alarms_by_cause' => $activity->falseAlarmCauses,
            'real_events_by_local_hour' => array_filter($activity->realByHour, static fn (int $count): bool => $count > 0),
            'real_events_by_day' => $activity->realByDay,
            'cameras' => array_values(array_map(static fn (CameraActivity $camera): array => [
                'slug' => $camera->slug,
                'name' => $camera->name,
                'real_events' => $camera->real,
                'false_alarms' => $camera->falseAlarms,
                'false_alarms_by_cause' => $camera->falseAlarmCauses,
            ], $activity->cameras)),
        ];
    }

    /**
     * @return list<array{name: string, label: string, subject: string, description: ?string}>
     */
    #[McpTool(
        name: 'list_known_individuals',
        description: 'List the known individuals the AI recognises by reference photos, e.g. the household\'s cat, with the name to use in search_camera_events and correct_camera_event.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function knownIndividuals(): array
    {
        return array_map(static fn (Individual $individual): array => [
            'name' => $individual->name,
            'label' => $individual->label,
            'subject' => $individual->subject,
            'description' => $individual->description,
        ], $this->settings->settings()->individuals);
    }

    /**
     * @return array<string, mixed> the corrected event
     */
    #[McpTool(
        name: 'correct_camera_event',
        description: 'Correct the AI\'s answer for one event when the user says what it really was, e.g. "that was Mochi" or "that was only the wind" (verdict false_alarm, cause wind). The AI learns from corrections for later events of that camera. Empty values keep what is set.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function correct(
        int $id,
        #[Schema(description: 'real or false_alarm; empty to keep', enum: ['', 'real', 'false_alarm'])]
        string $verdict = '',
        #[Schema(description: 'Name of a known individual from list_known_individuals, "unknown" for none of them; empty to keep')]
        string $individual = '',
        #[Schema(description: 'Who or what it was in a few words, e.g. "the postman"; empty to keep', maxLength: 120)]
        string $label = '',
        #[Schema(description: 'What caused a false alarm, e.g. spider_web or wind; empty to keep')]
        string $cause = '',
    ): array {
        $event = $this->events->findOneBy(['id' => $id]) ?? throw new InvalidArgumentException(\sprintf('Event %d does not exist.', $id));
        if ('' !== $individual && CameraEvent::NO_KNOWN_INDIVIDUAL !== $individual && !$this->settings->settings()->individual($individual) instanceof Individual) {
            throw new InvalidArgumentException(\sprintf('"%s" is no known individual; list_known_individuals names them.', $individual));
        }

        $causes = $this->settings->settings()->falseAlarmCauses;
        if ('' !== $cause && !$causes->has($cause)) {
            throw new InvalidArgumentException(\sprintf('"%s" is no false-alarm cause. Known: %s.', $cause, implode(', ', $causes->names())));
        }

        $this->labeler->relabel(
            $event,
            '' === trim($label) ? $event->getLabel() : mb_substr(trim($label), 0, 120),
            '' === $verdict ? $event->getVerdictOverride() : Verdict::from($verdict),
            '' === $individual ? $event->getIndividualOverride() : $individual,
            '' === $cause ? $event->getFalseAlarmCauseOverride() : $cause,
        );

        return $this->presenter->present($event);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'daily_report', description: 'A short report of what the home cameras saw on one day')]
    public function dailyReport(
        #[Schema(description: 'Local date, YYYY-MM-DD; empty for today')]
        string $date = '',
    ): array {
        $day = '' === $date ? $this->homeTimeZone->toLocal($this->clock->now()) : new DateTimeImmutable($date, $this->homeTimeZone->zone());
        [$from, $to] = $this->homeTimeZone->dayBounds($day);

        return [[
            'role' => 'user',
            'content' => \sprintf(
                'Write a short report of what our home cameras saw on %s. First call camera_activity with from "%s" and to "%s" for the exact counts, then search_camera_events for the same range to read the real events, and get_camera_event for any unusual one to look at its frames. Mention known individuals by name, anything unusual first, and the main false-alarm causes in one sentence. Keep it under 120 words.',
                $day->format('l, j F Y'),
                $this->local($from),
                $this->local($to),
            ),
        ]];
    }

    private function local(DateTimeImmutable $at): string
    {
        return $this->homeTimeZone->toLocal($at)->format(\DATE_ATOM);
    }
}
