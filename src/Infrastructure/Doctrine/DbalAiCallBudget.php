<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Clock\HomeTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Counts AI calls per home day in the ai_usage table with an atomic upsert.
 */
final readonly class DbalAiCallBudget implements AiCallBudgetInterface
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        #[Autowire('%env(int:AI_DAILY_CALL_LIMIT)%')]
        private int $dailyLimit,
    ) {
    }

    public function tryConsume(): bool
    {
        if ($this->dailyLimit <= 0) {
            return false;
        }

        $changed = $this->connection->executeStatement(
            'INSERT INTO ai_usage (day, calls) VALUES (:day, 1)
             ON CONFLICT (day) DO UPDATE SET calls = calls + 1 WHERE calls < :limit',
            ['day' => $this->today(), 'limit' => $this->dailyLimit],
        );

        return $changed > 0;
    }

    public function usedToday(): int
    {
        $calls = $this->connection->fetchOne('SELECT calls FROM ai_usage WHERE day = :day', ['day' => $this->today()]);

        return is_numeric($calls) ? (int) $calls : 0;
    }

    public function dailyLimit(): int
    {
        return $this->dailyLimit;
    }

    private function today(): string
    {
        return $this->homeTimeZone->toLocal($this->clock->now())->format('Y-m-d');
    }
}
