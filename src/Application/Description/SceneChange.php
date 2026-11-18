<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Frame\BrightnessGrid;

/**
 * Compares an event's frames with the frames of false alarms the AI checked, as brightness grids. Cells that already
 * changed between those frames, like a spider's thread swinging in front of the lens or the clock overlay, and their
 * neighbours do not count; any other cell that changed is something new.
 */
final readonly class SceneChange
{
    /** Brightness difference, from 0 to 255, from which a cell counts as changed. */
    private const float THRESHOLD = 30.0;

    /** How many cells around a cell that already changed are left out as well. */
    private const int MARGIN = 2;

    /**
     * @param non-empty-list<BrightnessGrid> $references the frames of the checked false alarms
     * @param non-empty-list<BrightnessGrid> $frames     the frames of the new event
     */
    public function showsSomethingNew(array $references, array $frames): bool
    {
        $shape = $references[0];
        foreach ([...$references, ...$frames] as $grid) {
            if (!$grid->hasShapeOf($shape)) {
                return true;
            }
        }

        $valuesPerCell = [];
        foreach ($references as $reference) {
            foreach ($this->withoutOverallBrightness($reference) as $index => $value) {
                $valuesPerCell[$index][] = $value;
            }
        }

        $usual = [];
        $restless = [];
        foreach ($valuesPerCell as $index => $values) {
            sort($values);
            $usual[$index] = $values[intdiv(\count($values), 2)] ?? 0.0;
            if (max($values) - min($values) > self::THRESHOLD) {
                $restless += $this->neighbourhood($index, $shape);
            }
        }

        foreach ($frames as $frame) {
            foreach ($this->withoutOverallBrightness($frame) as $index => $value) {
                if (!isset($restless[$index]) && abs($value - ($usual[$index] ?? $value)) > self::THRESHOLD) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Infrared and daylight exposure shift the whole frame; only the differences to its average count.
     *
     * @return list<float>
     */
    private function withoutOverallBrightness(BrightnessGrid $grid): array
    {
        $average = array_sum($grid->cells) / \count($grid->cells);

        return array_map(static fn (float $value): float => $value - $average, $grid->cells);
    }

    /**
     * @return array<int, true> the cell and the cells around it
     */
    private function neighbourhood(int $index, BrightnessGrid $shape): array
    {
        $rows = intdiv(\count($shape->cells), $shape->columns);
        $x = $index % $shape->columns;
        $y = intdiv($index, $shape->columns);
        $cells = [];
        for ($ny = max(0, $y - self::MARGIN); $ny <= min($rows - 1, $y + self::MARGIN); ++$ny) {
            for ($nx = max(0, $x - self::MARGIN); $nx <= min($shape->columns - 1, $x + self::MARGIN); ++$nx) {
                $cells[$ny * $shape->columns + $nx] = true;
            }
        }

        return $cells;
    }
}
