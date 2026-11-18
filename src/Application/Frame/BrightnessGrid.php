<?php

declare(strict_types=1);

namespace App\Application\Frame;

/**
 * A frame scaled down to a few cells, as the brightness of each cell from 0 to 255.
 */
final readonly class BrightnessGrid
{
    /**
     * @param positive-int          $columns
     * @param non-empty-list<float> $cells   row by row
     */
    public function __construct(
        public int $columns,
        public array $cells,
    ) {
    }

    public function hasShapeOf(self $other): bool
    {
        return $this->columns === $other->columns && \count($this->cells) === \count($other->cells);
    }
}
