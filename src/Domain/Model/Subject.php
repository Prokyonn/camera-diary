<?php

declare(strict_types=1);

namespace App\Domain\Model;

enum Subject: string
{
    case Person = 'person';
    case Cat = 'cat';
    case Dog = 'dog';
    case Bird = 'bird';
    case OtherAnimal = 'other_animal';
    case Vehicle = 'vehicle';
    case Nothing = 'nothing';
    case Other = 'other';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
