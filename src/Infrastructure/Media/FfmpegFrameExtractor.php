<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Writes one JPEG frame, scaled to FRAME_WIDTH and without metadata, from a video position or an image.
 */
final readonly class FfmpegFrameExtractor implements FrameExtractorInterface
{
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(
        #[Autowire(env: 'FFMPEG_PATH')]
        private string $ffmpegPath,
        #[Autowire(env: 'FFPROBE_PATH')]
        private string $ffprobePath,
        #[Autowire('%env(int:FRAME_WIDTH)%')]
        private int $frameWidth,
    ) {
    }

    public function extract(string $sourcePath, ?float $atSeconds, string $targetPath): void
    {
        $command = [$this->ffmpegPath, '-hide_banner', '-loglevel', 'error', '-nostdin'];
        if (null !== $atSeconds) {
            $command[] = '-ss';
            $command[] = \sprintf('%.3f', $atSeconds);
        }
        $command[] = '-i';
        $command[] = $sourcePath;
        $command[] = '-frames:v';
        $command[] = '1';
        $command[] = '-map_metadata';
        $command[] = '-1';
        $command[] = '-vf';
        $command[] = \sprintf("scale='min(%d,iw)':-2", $this->frameWidth);
        $command[] = '-q:v';
        $command[] = '3';
        $command[] = '-update';
        $command[] = '1';
        $command[] = '-y';
        $command[] = $targetPath;

        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ExceptionInterface $exception) {
            throw new FrameExtractionFailedException(\sprintf('ffmpeg did not finish for "%s": %s', basename($sourcePath), $exception->getMessage()), 0, $exception);
        }

        clearstatcache(true, $targetPath);
        if (!$process->isSuccessful() || !is_file($targetPath) || 0 === filesize($targetPath)) {
            throw new FrameExtractionFailedException(\sprintf('ffmpeg wrote no frame for "%s": %s', basename($sourcePath), trim($process->getErrorOutput())));
        }
    }

    public function duration(string $videoPath): ?float
    {
        $process = new Process([
            $this->ffprobePath,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return null;
        }

        $duration = trim($process->getOutput());
        if (!$process->isSuccessful() || !is_numeric($duration) || (float) $duration <= 0) {
            return null;
        }

        return (float) $duration;
    }
}
