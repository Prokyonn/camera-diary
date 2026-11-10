<?php

declare(strict_types=1);

namespace App\Application\Ai;

/**
 * Caps AI calls per day in the home time zone.
 */
interface AiCallBudgetInterface
{
    /**
     * Counts one call if today's limit allows it.
     */
    public function tryConsume(): bool;

    public function usedToday(): int;

    public function dailyLimit(): int;
}
