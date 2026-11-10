<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One worker run: describes the closed pending events that are due, newest first.
 */
final readonly class PendingEventRunner
{
    public const int BATCH_SIZE = 20;
    private const int LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private EventProcessor $processor,
        private CameraEventRepositoryInterface $events,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, int>|null count per ProcessOutcome value; null when another run holds the lock
     */
    public function run(int $limit = self::BATCH_SIZE): ?array
    {
        $lock = $this->lockFactory->createLock('camera-event-description', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return $this->describeDue($limit);
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
            if (ProcessOutcome::OverBudget === $outcome) {
                break;
            }
        }

        return $outcomes;
    }
}
