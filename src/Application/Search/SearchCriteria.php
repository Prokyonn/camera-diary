<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Model\Side;
use DateTimeImmutable;

final readonly class SearchCriteria
{
    /**
     * @param list<string> $cameras camera slugs; empty means all cameras
     */
    public function __construct(
        public string $text = '',
        public array $cameras = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?Side $side = null,
        public bool $includeFalseAlarms = false,
        public int $limit = 10,
    ) {
    }
}
