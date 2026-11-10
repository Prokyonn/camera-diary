<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Application\Clock\HomeTimeZone;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameStorageInterface;
use App\Domain\Model\CameraEvent;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Frames live under <data>/frames/<camera>/<YYYY>/<MM>/<DD>/<event id>-<n>.jpg; the database keeps the relative path.
 */
final readonly class FilesystemFrameStorage implements FrameStorageInterface
{
    public function __construct(
        #[Autowire('%app.data_dir%/frames')]
        private string $framesDir,
        private HomeTimeZone $homeTimeZone,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function relativePathFor(CameraEvent $event, int $index): string
    {
        return \sprintf(
            '%s/%s/%d-%d.jpg',
            $event->getCamera()->getSlug(),
            $this->homeTimeZone->toLocal($event->getStartedAt())->format('Y/m/d'),
            $event->getId(),
            $index,
        );
    }

    public function absolutePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            throw new InvalidArgumentException(\sprintf('Invalid frame path "%s".', $relativePath));
        }

        return $this->framesDir.'/'.$relativePath;
    }

    public function prepare(string $relativePath): string
    {
        $path = $this->absolutePath($relativePath);
        $this->filesystem->mkdir(\dirname($path));

        return $path;
    }

    public function imageSize(string $relativePath): array
    {
        $size = @getimagesize($this->absolutePath($relativePath));
        if (false === $size || $size[0] <= 0 || $size[1] <= 0) {
            throw new FrameExtractionFailedException(\sprintf('The frame "%s" is no image.', $relativePath));
        }

        return [$size[0], $size[1]];
    }

    public function remove(array $relativePaths): void
    {
        $this->filesystem->remove(array_map($this->absolutePath(...), $relativePaths));
    }
}
