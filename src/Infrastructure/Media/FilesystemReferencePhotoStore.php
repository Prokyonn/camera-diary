<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Application\Individual\CropBox;
use App\Application\Individual\InvalidReferencePhotoException;
use App\Application\Individual\ReferencePhoto;
use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Domain\Model\TermName;
use GdImage;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Reference photos added in the app live under <data>/individuals/<name>/<YYYYMMDD-HHMMSS>-<random>.jpg.
 */
final readonly class FilesystemReferencePhotoStore implements ReferencePhotoStoreInterface
{
    private const string ID_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{8}\.jpg$/';

    public function __construct(
        #[Autowire('%app.data_dir%/individuals')]
        private string $directory,
        private ClockInterface $clock,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function add(string $individual, string $imagePath, ?CropBox $crop = null): ReferencePhoto
    {
        $image = $this->read($imagePath);
        if ($crop instanceof CropBox) {
            $image = $this->crop($image, $crop);
        }

        $id = \sprintf('%s-%s.jpg', $this->clock->now()->format('Ymd-His'), bin2hex(random_bytes(4)));
        $path = $this->folder($individual).'/'.$id;
        $this->filesystem->mkdir(\dirname($path));
        if (!imagejpeg($image, $path, 90)) {
            throw new InvalidReferencePhotoException(\sprintf('Could not write "%s".', $path));
        }

        return new ReferencePhoto($path, $id);
    }

    public function findBy(string $individual): array
    {
        $files = glob($this->folder($individual).'/*.jpg');
        if (false === $files) {
            return [];
        }

        $ids = array_values(array_filter(array_map(basename(...), $files), static fn (string $id): bool => 1 === preg_match(self::ID_PATTERN, $id)));
        rsort($ids);

        return array_map(fn (string $id): ReferencePhoto => new ReferencePhoto($this->folder($individual).'/'.$id, $id), $ids);
    }

    public function remove(string $individual, string $storedId): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $storedId)) {
            throw new InvalidArgumentException(\sprintf('Invalid reference photo id "%s".', $storedId));
        }

        $this->filesystem->remove($this->folder($individual).'/'.$storedId);
    }

    private function folder(string $individual): string
    {
        if (!TermName::isValid($individual)) {
            throw new InvalidArgumentException(\sprintf('Invalid individual name "%s".', $individual));
        }

        return $this->directory.'/'.$individual;
    }

    private function read(string $path): GdImage
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        $image = false === $data ? false : @imagecreatefromstring($data);
        if (!$image instanceof GdImage) {
            throw new InvalidReferencePhotoException(\sprintf('"%s" is no readable image.', $path));
        }

        return $image;
    }

    private function crop(GdImage $image, CropBox $crop): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $cropped = imagecrop($image, [
            'x' => (int) round($crop->x * $width),
            'y' => (int) round($crop->y * $height),
            'width' => max(1, min($width, (int) round($crop->width * $width))),
            'height' => max(1, min($height, (int) round($crop->height * $height))),
        ]);
        if (!$cropped instanceof GdImage) {
            throw new InvalidReferencePhotoException('Could not crop the image.');
        }

        return $cropped;
    }
}
