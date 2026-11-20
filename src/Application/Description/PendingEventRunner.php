<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One worker run: describes the closed pending events that are due, newest first.
 */
final readonly class PendingEventRunner
{
    public const string LOCK_KEY = 'camera-event-description';
    private const int LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private EventProcessor $processor,
        private CameraEventRepositoryInterface $events,
        private LockFactory $lockFactory,
        private AiCallBudgetInterface $budget,
        private SettingsProviderInterface $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, int>|null count per ProcessOutcome value; null when another run holds the lock
     */
    public function run(?int $limit = null): ?array
    {
        return $this->locked(fn (): array => $this->describeDue($limit ?? $this->settings->settings()->description->batchSize));
    }

    /**
     * Describes one event now, also if it was described before; null when another run holds the lock.
     */
    public function describe(CameraEvent $event): ?ProcessOutcome
    {
        return $this->locked(fn (): ProcessOutcome => $this->processor->process($event));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T|null null when another run holds the lock
     */
    private function locked(callable $work): mixed
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, int>
     */
    private function describeDue(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $due = $this->events->findBy([
            'status' => EventStatus::Pending,
            'open' => false,
            'dueAt' => $this->clock->now(),
        ], ['startedAt' => 'desc'], $limit);

        $outcomes = [];
        foreach ($due as $event) {
            $outcome = $this->processor->process($event);
            $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;
            if (ProcessOutcome::OverBudget === $outcome && !$this->budget->hasCallsLeftToday()) {
                break;
            }
        }

        return $outcomes;
    }
}
