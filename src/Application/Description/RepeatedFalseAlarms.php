<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Frame\BrightnessGrid;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;

/**
 * Spots an event that repeats a camera's ongoing false alarm, like a spider in front of the lens all night: the
 * camera's last false alarms had the same cause, and the frames show nothing new. Real events in between, like a cat
 * crossing the lawn, do not end the streak.
 */
final readonly class RepeatedFalseAlarms
{
    private const int GRID_COLUMNS = 64;

    /** How many false alarms with the same cause in a row make a streak. */
    private const int STREAK = 2;

    private const string WINDOW = '-3 hours';

    /** How many checked false alarms of the streak the frames are compared with. */
    private const int REFERENCES = 5;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private FrameStorageInterface $frameStorage,
        private SceneChange $sceneChange,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * The latest false alarm the AI checked that the event repeats; null when the AI should look at the event.
     */
    public function checkedOriginalOf(CameraEvent $event): ?CameraEvent
    {
        if (!$this->settings->settings()->ai->skipRepeats || [] === $event->getFramePaths()) {
            return null;
        }

        $streak = $this->streakBefore($event);
        if (\count($streak) < self::STREAK) {
            return null;
        }

        $checked = array_values(array_filter($streak, static fn (CameraEvent $earlier): bool => !$earlier->isRepeat() && [] !== $earlier->getFramePaths()));
        if ([] === $checked || $this->recheckDue($checked[0], $event)) {
            return null;
        }

        $references = $this->grids(array_merge(...array_map(static fn (CameraEvent $earlier): array => $earlier->getFramePaths(), \array_slice($checked, 0, self::REFERENCES))));
        $frames = $this->grids($event->getFramePaths());
        if (null === $references || null === $frames || $this->sceneChange->showsSomethingNew($references, $frames)) {
            return null;
        }

        return $checked[0];
    }

    /**
     * @return list<CameraEvent> the camera's latest false alarms, newest first, while they have one cause; real events in between are skipped
     */
    private function streakBefore(CameraEvent $event): array
    {
        $recent = $this->events->findBy(
            [
                'camera' => $event->getCamera(),
                'status' => EventStatus::Described,
                'startedAtOrAfter' => $event->getStartedAt()->modify(self::WINDOW),
                'startedBefore' => $event->getStartedAt(),
            ],
            ['startedAt' => 'desc'],
        );

        $falseAlarms = array_values(array_filter($recent, static fn (CameraEvent $earlier): bool => $earlier->isFalseAlarm()));
        $cause = ($falseAlarms[0] ?? null)?->getEffectiveFalseAlarmCause();
        $streak = [];
        foreach ($falseAlarms as $earlier) {
            if (null === $cause || $earlier->getEffectiveFalseAlarmCause() !== $cause) {
                break;
            }

            $streak[] = $earlier;
        }

        return $streak;
    }

    private function recheckDue(CameraEvent $latestChecked, CameraEvent $event): bool
    {
        $minutes = $this->settings->settings()->ai->repeatRecheckMinutes;

        return $minutes > 0 && $latestChecked->getStartedAt() < $event->getStartedAt()->modify(\sprintf('-%d minutes', $minutes));
    }

    /**
     * @param list<string> $framePaths
     *
     * @return non-empty-list<BrightnessGrid>|null null when there are none or one is unreadable
     */
    private function grids(array $framePaths): ?array
    {
        $grids = [];
        foreach ($framePaths as $framePath) {
            $grid = $this->frameStorage->brightnessGrid($framePath, self::GRID_COLUMNS);
            if (!$grid instanceof BrightnessGrid) {
                return null;
            }

            $grids[] = $grid;
        }

        return [] === $grids ? null : $grids;
    }
}
