<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum DetectionType: string
{
    case Person = 'person';
    case Animal = 'animal';
    case Vehicle = 'vehicle';
    case Motion = 'motion';
}
