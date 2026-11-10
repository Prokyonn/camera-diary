<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum EventStatus: string
{
    case Pending = 'pending';
    case Described = 'described';
    case NoFootage = 'no_footage';
    case Failed = 'failed';
}
