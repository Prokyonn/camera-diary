<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Media;

use App\Application\Individual\CropBox;
use App\Application\Individual\InvalidReferencePhotoException;
use App\Infrastructure\Media\FilesystemReferencePhotoStore;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FilesystemReferencePhotoStore::class)]
final class FilesystemReferencePhotoStoreTest extends TestCase
{
    private string $directory;

    private string $frame;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/camera-diary-individuals-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
        $this->frame = $this->directory.'/frame.jpg';
        $image = imagecreatetruecolor(200, 100);
        self::assertNotFalse($image);
        imagejpeg($image, $this->frame);
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-17 16:46:04'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testStoresTheCroppedPartAndListsTheNewestFirst(): void
    {
        $store = new FilesystemReferencePhotoStore($this->directory, $this->clock);

        $older = $store->add('anna', $this->frame);
        $this->clock->sleep(60);
        $newer = $store->add('anna', $this->frame, new CropBox(0.5, 0.25, 0.25, 0.5));

        self::assertStringStartsWith('20261117-164704-', (string) $newer->storedId);
        self::assertSame([200, 100], $this->imageSize($older->path));
        self::assertSame([50, 50], $this->imageSize($newer->path), 'Only the box is kept.');
        self::assertEquals([$newer, $older], $store->findBy('anna'));
        self::assertSame([], $store->findBy('luna'));
    }

    public function testRemovesAPhoto(): void
    {
        $store = new FilesystemReferencePhotoStore($this->directory, $this->clock);
        $photo = $store->add('anna', $this->frame);

        $store->remove('anna', (string) $photo->storedId);

        self::assertSame([], $store->findBy('anna'));
        self::assertFileDoesNotExist($photo->path);
    }

    public function testRejectsPathsOutsideItsFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FilesystemReferencePhotoStore($this->directory, $this->clock)->remove('anna', '../../events.sqlite');
    }

    public function testRejectsAnUnreadableImage(): void
    {
        $this->expectException(InvalidReferencePhotoException::class);

        new FilesystemReferencePhotoStore($this->directory, $this->clock)->add('anna', __FILE__);
    }

    /**
     * @return array{int, int}
     */
    private function imageSize(string $path): array
    {
        $size = getimagesize($path);
        self::assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}
