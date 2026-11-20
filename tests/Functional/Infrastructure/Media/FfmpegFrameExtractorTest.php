<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Media;

use App\Application\Frame\FrameExtractionFailedException;
use App\Infrastructure\Media\FfmpegFrameExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(FfmpegFrameExtractor::class)]
final class FfmpegFrameExtractorTest extends TestCase
{
    private string $dir;

    private FfmpegFrameExtractor $extractor;

    protected function setUp(): void
    {
        $ffmpeg = new ExecutableFinder()->find('ffmpeg');
        $ffprobe = new ExecutableFinder()->find('ffprobe');
        if (null === $ffmpeg || null === $ffprobe) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $this->dir = sys_get_temp_dir().'/camera-diary-ffmpeg-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
        new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=5:duration=2', '-pix_fmt', 'yuv420p', $this->dir.'/clip.mp4'])->mustRun();
        new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240', '-frames:v', '1', $this->dir.'/photo.jpg'])->mustRun();
        $this->extractor = new FfmpegFrameExtractor($ffmpeg, $ffprobe, 160);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            new Filesystem()->remove($this->dir);
        }
    }

    public function testCutsAFrameFromAVideoAndAnImage(): void
    {
        self::assertSame(2.0, $this->extractor->duration($this->dir.'/clip.mp4'));

        $this->extractor->extract($this->dir.'/clip.mp4', 1.0, $this->dir.'/from-video.jpg');
        $this->extractor->extract($this->dir.'/photo.jpg', null, $this->dir.'/from-photo.jpg');

        self::assertSame(160, getimagesize($this->dir.'/from-video.jpg')[0] ?? null, 'Scaled to FRAME_WIDTH.');
        self::assertFileExists($this->dir.'/from-photo.jpg');
    }

    public function testRefusesAPlaylistThatPointsElsewhere(): void
    {
        file_put_contents($this->dir.'/upload.mp4', "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXTINF:2,\nclip.mp4\n#EXT-X-ENDLIST\n");

        self::assertNull($this->extractor->duration($this->dir.'/upload.mp4'));
        $this->expectException(FrameExtractionFailedException::class);

        $this->extractor->extract($this->dir.'/upload.mp4', 1.0, $this->dir.'/frame.jpg');
    }
}
