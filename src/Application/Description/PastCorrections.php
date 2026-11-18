<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;

/**
 * The owner's latest corrections on an event's camera, which the AI gets as examples, so it does not repeat a mistake
 * such as calling the same swinging branch a person.
 */
final readonly class PastCorrections
{
    /** Reviewed events looked at to find the corrected ones; confirmations are skipped. */
    private const int CANDIDATES = 50;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @return list<CameraEvent> latest review first, at most ai.correction_examples
     */
    public function forEvent(CameraEvent $event): array
    {
        $limit = $this->settings->settings()->ai->correctionExamples;
        if (0 === $limit) {
            return [];
        }

        $reviewed = $this->events->findBy(
            ['camera' => $event->getCamera(), 'status' => EventStatus::Described, 'reviewed' => true],
            ['reviewedAt' => 'desc'],
            self::CANDIDATES,
        );
        $corrected = array_filter($reviewed, static fn (CameraEvent $candidate): bool => $candidate !== $event && $candidate->isCorrected());

        return \array_slice(array_values($corrected), 0, $limit);
    }
}
