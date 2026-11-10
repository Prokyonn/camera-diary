<?php

declare(strict_types=1);

namespace App\Application\Question;

final readonly class Answer
{
    /**
     * @param list<int> $eventIds the events the answer relies on, most relevant first
     */
    public function __construct(
        public string $text,
        public array $eventIds,
    ) {
    }
}
