<?php

declare(strict_types=1);

namespace App\Application\Frame;

use App\Domain\Model\CameraEvent;

/**
 * The database keeps relative frame paths; this resolves, fills and removes the files.
 */
interface FrameStorageInterface
{
    public function relativePathFor(CameraEvent $event, int $index): string;

    public function absolutePath(string $relativePath): string;

    /**
     * @return string the absolute path, with its folder created
     */
    public function prepare(string $relativePath): string;

    /**
     * @return array{int, int} width and height
     *
     * @throws FrameExtractionFailedException when the file is no image
     */
    public function imageSize(string $relativePath): array;

    /**
     * Whether the frame has (almost) no colour, as an infrared camera records at night; false for unreadable files.
     */
    public function isMonochrome(string $relativePath): bool;

    /**
     * The frame scaled down to the given number of columns, the rows keeping the aspect ratio; null for unreadable files.
     *
     * @param positive-int $columns
     */
    public function brightnessGrid(string $relativePath, int $columns): ?BrightnessGrid;

    /**
     * @param list<string> $relativePaths
     */
    public function remove(array $relativePaths): void;
}
