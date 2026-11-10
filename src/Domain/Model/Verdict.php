<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum Verdict: string
{
    case Real = 'real';
    case FalseAlarm = 'false_alarm';

    public function label(): string
    {
        return match ($this) {
            self::Real => 'Real',
            self::FalseAlarm => 'False alarm',
        };
    }
}
