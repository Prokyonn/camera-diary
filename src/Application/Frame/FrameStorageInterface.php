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
     * @param list<string> $relativePaths
     */
    public function remove(array $relativePaths): void;
}
