<?php

declare(strict_types=1);

namespace App\Application\Activity;

use App\Application\Clock\HomeTimeZone;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use DateTimeImmutable;

/**
 * Counts what the cameras saw, for the insights page and assistants that need exact numbers.
 */
final readonly class ActivityReport
{
    /** More events than any home camera setup records in a month. */
    private const int MAX_EVENTS = 20000;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @param ?string $cameraSlug null for all cameras
     */
    public function between(DateTimeImmutable $from, DateTimeImmutable $to, ?string $cameraSlug = null): Activity
    {
        $filters = ['startedAtOrAfter' => $from, 'startedBefore' => $to];
        if (null !== $cameraSlug) {
            $filters['cameraSlug'] = $cameraSlug;
        }

        $settings = $this->settings->settings();
        $real = $falseAlarms = $undescribed = $repeats = 0;
        $byWho = $causes = $names = $cameraReal = $cameraFalseAlarms = $cameraCauses = [];
        $byHour = array_fill(0, 24, 0);
        $byDay = $this->days($from, $to);

        foreach ($this->events->findBy($filters, ['startedAt' => 'asc'], self::MAX_EVENTS) as $event) {
            $slug = $event->getCamera()->getSlug();
            $names[$slug] = $event->getCamera()->getName();
            if (EventStatus::Described !== $event->getStatus()) {
                ++$undescribed;

                continue;
            }

            if ($event->isFalseAlarm()) {
                ++$falseAlarms;
                $repeats += $event->isRepeat() ? 1 : 0;
                $cameraFalseAlarms[$slug] = ($cameraFalseAlarms[$slug] ?? 0) + 1;
                $cause = $this->causeLabel($event);
                $causes[$cause] = ($causes[$cause] ?? 0) + 1;
                $cameraCauses[$slug][$cause] = ($cameraCauses[$slug][$cause] ?? 0) + 1;

                continue;
            }

            ++$real;
            $cameraReal[$slug] = ($cameraReal[$slug] ?? 0) + 1;
            $who = $settings->subjectLabel($event->getSubject() ?? 'other', $event->getEffectiveIndividual());
            $byWho[$who] = ($byWho[$who] ?? 0) + 1;
            foreach ($event->getAlsoSeen() as $other) {
                $label = $settings->individualLabel($other);
                if ($label !== $who) {
                    $byWho[$label] = ($byWho[$label] ?? 0) + 1;
                }
            }
            $local = $this->homeTimeZone->toLocal($event->getStartedAt());
            ++$byHour[(int) $local->format('G')];
            $day = $local->format('Y-m-d');
            if (isset($byDay[$day])) {
                ++$byDay[$day];
            }
        }

        asort($names);
        $cameras = [];
        foreach ($names as $slug => $name) {
            $cameraCause = $cameraCauses[$slug] ?? [];
            arsort($cameraCause);
            $cameras[$slug] = new CameraActivity($slug, $name, $cameraReal[$slug] ?? 0, $cameraFalseAlarms[$slug] ?? 0, $cameraCause);
        }
        arsort($byWho);
        arsort($causes);

        return new Activity($from, $to, $real, $falseAlarms, $undescribed, $repeats, $byWho, $causes, $cameras, $byHour, $byDay);
    }

    private function causeLabel(CameraEvent $event): string
    {
        $cause = $event->getEffectiveFalseAlarmCause();

        return null === $cause ? 'Set by hand' : $this->settings->settings()->falseAlarmCauses->label($cause);
    }

    /**
     * @return array<string, int> every local day in the range, with 0
     */
    private function days(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [];
        for ($day = $this->homeTimeZone->toLocal($from)->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = 0;
        }

        return $days;
    }
}
