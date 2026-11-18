<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * How sure the AI is of its answer; anything below high asks the owner to check the event.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Sure',
            self::Medium => 'Fairly sure',
            self::Low => 'Unsure',
        };
    }
}
