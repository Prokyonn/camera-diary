<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Clock\HomeTimeZone;
use App\Application\Settings\CallPool;
use App\Application\Settings\SettingsProviderInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Counts AI calls per home day in ai_usage, and per call pool in ai_pool_usage, with atomic upserts in one transaction.
 */
final readonly class DbalAiCallBudget implements AiCallBudgetInterface
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function tryConsume(?CallPool $pool = null): bool
    {
        $limit = $this->settings->settings()->ai->dailyCallLimit;
        if ($limit <= 0 || ($pool instanceof CallPool && $pool->dailyCallLimit <= 0)) {
            return false;
        }

        $day = $this->today();
        $this->connection->beginTransaction();
        try {
            $consumed = $this->increment(
                'INSERT INTO ai_usage (day, calls) VALUES (:day, 1)
                 ON CONFLICT (day) DO UPDATE SET calls = calls + 1 WHERE calls < :limit',
                ['day' => $day, 'limit' => $limit],
            ) && (!$pool instanceof CallPool || $this->increment(
                'INSERT INTO ai_pool_usage (day, pool, calls) VALUES (:day, :pool, 1)
                 ON CONFLICT (day, pool) DO UPDATE SET calls = calls + 1 WHERE calls < :limit',
                ['day' => $day, 'pool' => $pool->name, 'limit' => $pool->dailyCallLimit],
            ));
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        $consumed ? $this->connection->commit() : $this->connection->rollBack();

        return $consumed;
    }

    public function usedToday(?CallPool $pool = null): int
    {
        $calls = $pool instanceof CallPool
            ? $this->connection->fetchOne('SELECT calls FROM ai_pool_usage WHERE day = :day AND pool = :pool', ['day' => $this->today(), 'pool' => $pool->name])
            : $this->connection->fetchOne('SELECT calls FROM ai_usage WHERE day = :day', ['day' => $this->today()]);

        return is_numeric($calls) ? (int) $calls : 0;
    }

    public function hasCallsLeftToday(): bool
    {
        return $this->usedToday() < $this->settings->settings()->ai->dailyCallLimit;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function increment(string $sql, array $parameters): bool
    {
        return $this->connection->executeStatement($sql, $parameters) > 0;
    }

    private function today(): string
    {
        return $this->homeTimeZone->toLocal($this->clock->now())->format('Y-m-d');
    }
}
