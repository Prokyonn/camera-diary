<?php

declare(strict_types=1);

namespace App\Application\Individual;

use InvalidArgumentException;

/**
 * Part of an image, in fractions of its width and height: (0, 0, 1, 1) is the whole image.
 */
final readonly class CropBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 || $x + $width > 1.0001 || $y + $height > 1.0001) {
            throw new InvalidArgumentException('The crop box must lie inside the image.');
        }
    }
}
