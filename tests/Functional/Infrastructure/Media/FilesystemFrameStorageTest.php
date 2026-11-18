<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Media;

use App\Application\Clock\HomeTimeZone;
use App\Infrastructure\Media\FilesystemFrameStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FilesystemFrameStorage::class)]
final class FilesystemFrameStorageTest extends TestCase
{
    private string $dir;

    private FilesystemFrameStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/camera-diary-storage-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
        $this->storage = new FilesystemFrameStorage($this->dir, new HomeTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testTellsInfraredFramesFromColourFrames(): void
    {
        $this->frame('night.jpg', 90, 90, 95);
        $this->frame('day.jpg', 60, 140, 40);

        self::assertTrue($this->storage->isMonochrome('night.jpg'));
        self::assertFalse($this->storage->isMonochrome('day.jpg'));
        self::assertFalse($this->storage->isMonochrome('missing.jpg'), 'An unreadable frame counts as colour.');
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private function frame(string $name, int $red, int $green, int $blue): void
    {
        $image = imagecreatetruecolor(32, 18);
        self::assertNotFalse($image);
        $colour = imagecolorallocate($image, $red, $green, $blue);
        self::assertNotFalse($colour);
        imagefill($image, 0, 0, $colour);
        imagejpeg($image, $this->dir.'/'.$name);
    }
}
