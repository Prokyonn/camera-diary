<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Application\Clock\HomeTimeZone;
use App\Domain\Repository\CameraEventRepositoryInterface;
use DateTimeImmutable;

/**
 * The events of one local day, optionally of one camera or detection type, false alarms hidden unless asked for.
 *
 * @phpstan-import-type CameraEventFilters from CameraEventRepositoryInterface
 */
final readonly class Timeline
{
    public function __construct(
        private CameraEventRepositoryInterface $events,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    public function day(DateTimeImmutable $day, ?string $camera = null, ?string $detectionType = null, bool $falseAlarms = false): TimelineDay
    {
        [$from, $to] = $this->homeTimeZone->dayBounds($day);
        $filters = $this->filters($camera, $detectionType, $falseAlarms);
        $events = $this->events->findBy([...$filters, 'startedAtOrAfter' => $from, 'startedBefore' => $to], ['startedAt' => 'desc']);

        return new TimelineDay(
            $events,
            [] === $events ? $this->events->findOneBy([...$filters, 'startedBefore' => $from], ['startedAt' => 'desc']) : null,
        );
    }

    /**
     * @return CameraEventFilters
     */
    private function filters(?string $camera, ?string $detectionType, bool $falseAlarms): array
    {
        $filters = [];
        if (null !== $camera) {
            $filters['cameraSlug'] = $camera;
        }
        if (null !== $detectionType) {
            $filters['detectionType'] = $detectionType;
        }
        if (!$falseAlarms) {
            $filters['falseAlarm'] = false;
        }

        return $filters;
    }
}
