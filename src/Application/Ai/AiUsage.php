<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Application\Settings\SettingsProviderInterface;

/**
 * Today's AI calls against the daily limits: in total, then per call pool.
 */
final readonly class AiUsage
{
    public function __construct(
        private AiCallBudgetInterface $budget,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @return list<array{label: string, used: int, limit: int}>
     */
    public function today(): array
    {
        $ai = $this->settings->settings()->ai;
        $usage = [['label' => 'AI calls today', 'used' => $this->budget->usedToday(), 'limit' => $ai->dailyCallLimit]];
        foreach ($ai->callPools as $pool) {
            $usage[] = ['label' => $pool->label(), 'used' => $this->budget->usedToday($pool), 'limit' => $pool->dailyCallLimit];
        }

        return $usage;
    }
}
