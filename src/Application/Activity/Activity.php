<?php

declare(strict_types=1);

namespace App\Application\Activity;

use DateTimeImmutable;

/**
 * What the cameras saw in a time range, counted.
 */
final readonly class Activity
{
    /**
     * @param int                           $repeats          false alarms filed as repeats, without an AI call
     * @param array<string, int>            $byWho            real events per subject label, a known individual by name ("Luna", "Other cat", "Person"), most first
     * @param array<string, int>            $falseAlarmCauses false alarms per cause label, most first
     * @param array<string, CameraActivity> $cameras          per camera slug, by name
     * @param array<int, int>               $realByHour       real events per local hour, 0 to 23
     * @param array<string, int>            $realByDay        real events per local day (Y-m-d), oldest first
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $real,
        public int $falseAlarms,
        public int $undescribed,
        public int $repeats,
        public array $byWho,
        public array $falseAlarmCauses,
        public array $cameras,
        public array $realByHour,
        public array $realByDay,
    ) {
    }

    public function total(): int
    {
        return $this->real + $this->falseAlarms + $this->undescribed;
    }
}
