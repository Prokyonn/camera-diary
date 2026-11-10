<?php

declare(strict_types=1);

namespace App\Application\Frame;

/**
 * Turns a video position or an image into a normalized JPEG frame.
 */
interface FrameExtractorInterface
{
    /**
     * @param float|null $atSeconds position in a video; null for an image
     *
     * @throws FrameExtractionFailedException
     */
    public function extract(string $sourcePath, ?float $atSeconds, string $targetPath): void;

    /**
     * @return float|null null when the file is no readable video
     */
    public function duration(string $videoPath): ?float;
}
