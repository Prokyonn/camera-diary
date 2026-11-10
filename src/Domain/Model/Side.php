<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum Side: string
{
    case OurProperty = 'our_property';
    case Outside = 'outside';
    case Both = 'both';
    case Unclear = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::OurProperty => 'On our property',
            self::Outside => 'Outside our property',
            self::Both => 'Both',
            self::Unclear => 'Unclear',
        };
    }
}
