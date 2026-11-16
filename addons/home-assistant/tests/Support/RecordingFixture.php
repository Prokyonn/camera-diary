<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Support;

use App\Application\Clock\HomeTimeZone;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Builds a Surveillance Station-like recordings folder.
 */
final readonly class RecordingFixture
{
    public function __construct(
        public string $dir,
        private HomeTimeZone $homeTimeZone = new HomeTimeZone('Europe/Vienna'),
    ) {
    }

    public static function ffmpeg(): ?string
    {
        return new ExecutableFinder()->find('ffmpeg');
    }

    /**
     * An empty file named like a recording; enough to test the lookup.
     */
    public function placeholder(string $folder, DateTimeImmutable $startedAt): string
    {
        $path = $this->pathFor($folder, $startedAt);
        new Filesystem()->dumpFile($path, '');

        return $path;
    }

    /**
     * A real ten-second video, so ffmpeg can cut frames from it.
     */
    public function video(string $folder, DateTimeImmutable $startedAt, int $seconds = 10): string
    {
        return self::videoFile($this->pathFor($folder, $startedAt), $seconds);
    }

    public static function videoFile(string $path, int $seconds = 10): string
    {
        new Filesystem()->mkdir(\dirname($path));
        $process = new Process([
            self::ffmpeg() ?? throw new RuntimeException('ffmpeg is missing.'),
            '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', \sprintf('testsrc=size=640x360:rate=5:duration=%d', $seconds),
            '-pix_fmt', 'yuv420p',
            $path,
        ]);
        $process->mustRun();

        return $path;
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private function pathFor(string $folder, DateTimeImmutable $startedAt): string
    {
        $local = $this->homeTimeZone->toLocal($startedAt);

        return \sprintf(
            '%s/%s/%s/%s-%s-%s-7.mp4',
            $this->dir,
            $folder,
            $local->format('YmdA'),
            $folder,
            $local->format('Ymd-His'),
            $startedAt->format('Uv'),
        );
    }
}
