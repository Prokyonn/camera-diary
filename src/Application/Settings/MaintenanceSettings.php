<?php

declare(strict_types=1);

namespace App\Application\Settings;

final readonly class MaintenanceSettings
{
    public function __construct(
        public int $nightlyHour,
        public int $nightlyMinute,
        public int $falseAlarmFrameDays,
        public int $backupsKept,
    ) {
    }
}
