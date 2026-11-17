<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * AI calls per day and call pool in the home time zone; written by DbalAiCallBudget with an atomic upsert.
 */
#[ORM\Entity]
class AiPoolUsage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $day,
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $pool,
        #[ORM\Column]
        private int $calls = 0,
    ) {
    }

    public function getDay(): DateTimeImmutable
    {
        return $this->day;
    }

    public function getPool(): string
    {
        return $this->pool;
    }

    public function getCalls(): int
    {
        return $this->calls;
    }
}
