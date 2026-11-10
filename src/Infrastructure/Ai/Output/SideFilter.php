<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Domain\Model\Side;

enum SideFilter: string
{
    case Any = 'any';
    case OurProperty = 'our_property';
    case Outside = 'outside';

    public function side(): ?Side
    {
        return match ($this) {
            self::Any => null,
            self::OurProperty => Side::OurProperty,
            self::Outside => Side::Outside,
        };
    }
}
