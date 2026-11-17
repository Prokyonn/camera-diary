<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Model;

use App\Domain\Model\CameraEvent;
use DateTimeImmutable;

/**
 * Detections less than maxGapSeconds apart form one event; an event lasts at most maxDurationSeconds.
 */
final readonly class GroupingRule
{
    public function __construct(
        public int $maxGapSeconds,
        public int $maxDurationSeconds,
    ) {
    }

    public function joins(CameraEvent $event, DateTimeImmutable $detectedAt): bool
    {
        $at = $detectedAt->getTimestamp();
        $start = $event->getStartedAt()->getTimestamp();

        return $at >= $start
            && $at - $event->getEndedAt()->getTimestamp() <= $this->maxGapSeconds
            && $at - $start <= $this->maxDurationSeconds;
    }

    /**
     * Events that ended before this can take no more detections.
     */
    public function closedBefore(DateTimeImmutable $importedUntil): DateTimeImmutable
    {
        return $importedUntil->modify(\sprintf('-%d seconds', $this->maxGapSeconds));
    }
}
