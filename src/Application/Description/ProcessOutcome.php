<?php

declare(strict_types=1);

namespace App\Application\Description;

enum ProcessOutcome: string
{
    case Described = 'described';
    case Waiting = 'waiting';
    case NoFootage = 'no_footage';
    case Failed = 'failed';
    case OverBudget = 'over_budget';
    case Open = 'open';

    /**
     * @param array<string, int> $counts count per outcome value
     */
    public static function summarize(array $counts): string
    {
        if ([] === $counts) {
            return 'nothing to describe';
        }

        $parts = [];
        foreach ($counts as $outcome => $count) {
            $parts[] = \sprintf('%s %d', str_replace('_', ' ', $outcome), $count);
        }

        return implode(', ', $parts);
    }
}
