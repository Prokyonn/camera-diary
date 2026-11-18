<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Model\Side;
use DateTimeImmutable;

final readonly class SearchCriteria
{
    /**
     * @param list<string> $cameras     camera slugs; empty means all cameras
     * @param list<string> $subjects    names from the subjects vocabulary; empty means any
     * @param list<string> $individuals names of known individuals; empty means anyone
     */
    public function __construct(
        public string $text = '',
        public array $cameras = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?Side $side = null,
        public bool $includeFalseAlarms = false,
        public int $limit = 10,
        public array $subjects = [],
        public array $individuals = [],
    ) {
    }

    public function withText(string $text): self
    {
        return new self($text, $this->cameras, $this->from, $this->to, $this->side, $this->includeFalseAlarms, $this->limit, $this->subjects, $this->individuals);
    }

    public function withLimit(int $limit): self
    {
        return new self($this->text, $this->cameras, $this->from, $this->to, $this->side, $this->includeFalseAlarms, $limit, $this->subjects, $this->individuals);
    }

    /**
     * The same time range and false-alarm choice without the camera, side, subject, individual and keywords: for a
     * question whose exact search found nothing.
     */
    public function broadened(): self
    {
        return new self('', [], $this->from, $this->to, null, $this->includeFalseAlarms, $this->limit);
    }

    public function isBroad(): bool
    {
        return '' === trim($this->text) && [] === $this->cameras && !$this->side instanceof Side && [] === $this->subjects && [] === $this->individuals;
    }

    public function hasTimeRange(): bool
    {
        return $this->from instanceof DateTimeImmutable || $this->to instanceof DateTimeImmutable;
    }
}
