<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Model;

use App\Domain\Model\CameraEvent;
use DateTimeImmutable;

/**
 * Detections less than two minutes apart form one event; an event is capped at ten minutes.
 */
final readonly class GroupingRule
{
    public const int MAX_GAP_SECONDS = 120;
    public const int MAX_DURATION_SECONDS = 600;

    public function joins(CameraEvent $event, DateTimeImmutable $detectedAt): bool
    {
        $at = $detectedAt->getTimestamp();
        $start = $event->getStartedAt()->getTimestamp();

        return $at >= $start
            && $at - $event->getEndedAt()->getTimestamp() <= self::MAX_GAP_SECONDS
            && $at - $start <= self::MAX_DURATION_SECONDS;
    }

    /**
     * Events that ended before this can take no more detections.
     */
    public function closedBefore(DateTimeImmutable $importedUntil): DateTimeImmutable
    {
        return $importedUntil->modify(\sprintf('-%d seconds', self::MAX_GAP_SECONDS));
    }
}
