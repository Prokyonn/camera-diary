<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum FalseAlarmCause: string
{
    case None = 'none';
    case SpiderWeb = 'spider_web';
    case Insect = 'insect';
    case Rain = 'rain';
    case Light = 'light';
    case Nothing = 'nothing';
    case Other = 'other';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
