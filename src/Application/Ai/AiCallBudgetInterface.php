<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Application\Settings\CallPool;

/**
 * Caps AI calls per day in the home time zone: in total, and per call pool for the events in one.
 */
interface AiCallBudgetInterface
{
    /**
     * Counts one call if today's limit and, when given, the pool's limit allow it.
     */
    public function tryConsume(?CallPool $pool = null): bool;

    /**
     * Calls used today, in total or in one pool.
     */
    public function usedToday(?CallPool $pool = null): int;

    public function hasCallsLeftToday(): bool;
}
