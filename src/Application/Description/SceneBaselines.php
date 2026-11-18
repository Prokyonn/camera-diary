<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;

/**
 * Finds a camera's usual view: the first frame of its latest false alarm with no visible cause, in the same light as
 * the event (an infrared camera records without colour at night).
 */
final readonly class SceneBaselines
{
    /** The false-alarm cause that means the frames show the empty scene. */
    public const string EMPTY_SCENE_CAUSE = 'nothing';

    private const int CANDIDATES = 20;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private FrameStorageInterface $frameStorage,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function forEvent(CameraEvent $event): ?SceneBaseline
    {
        $frame = $event->getFramePaths()[0] ?? null;
        if (null === $frame || !$this->settings->settings()->ai->sceneBaseline) {
            return null;
        }

        $monochrome = $this->frameStorage->isMonochrome($frame);
        $candidates = $this->events->findBy(
            ['camera' => $event->getCamera(), 'status' => EventStatus::Described, 'falseAlarm' => true, 'framesRemoved' => false],
            ['startedAt' => 'desc'],
            self::CANDIDATES,
        );
        foreach ($candidates as $candidate) {
            $candidateFrame = $candidate->getFramePaths()[0] ?? null;
            if ($candidate === $event || null === $candidateFrame || self::EMPTY_SCENE_CAUSE !== $candidate->getEffectiveFalseAlarmCause()) {
                continue;
            }

            if ($this->frameStorage->isMonochrome($candidateFrame) === $monochrome) {
                return new SceneBaseline($candidateFrame, $candidate->getStartedAt());
            }
        }

        return null;
    }
}
