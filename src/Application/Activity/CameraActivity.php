<?php

declare(strict_types=1);

namespace App\Application\Activity;

final readonly class CameraActivity
{
    /**
     * @param array<string, int> $falseAlarmCauses false alarms per cause label, most first
     */
    public function __construct(
        public string $slug,
        public string $name,
        public int $real,
        public int $falseAlarms,
        public array $falseAlarmCauses,
    ) {
    }

    public function topFalseAlarmCause(): ?string
    {
        return array_key_first($this->falseAlarmCauses);
    }

    /**
     * @return float 0 to 1
     */
    public function falseAlarmShare(): float
    {
        $described = $this->real + $this->falseAlarms;

        return 0 === $described ? 0.0 : $this->falseAlarms / $described;
    }
}
