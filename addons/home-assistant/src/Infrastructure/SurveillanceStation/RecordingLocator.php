<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\SurveillanceStation;

use App\Application\Clock\HomeTimeZone;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the continuous recording that contains a moment (Synology Surveillance Station layout).
 *
 * Files live in <folder>/<YYYYMMDD><AM|PM>/<name>-<YYYYMMDD>-<HHMMSS>-<epoch_ms>-<n>.mp4 and last up to 30 minutes;
 * the epoch in the name is the real start time.
 */
final readonly class RecordingLocator
{
    private const string FILE_PATTERN = '/^[A-Za-z0-9_-]+-\d{8}-\d{6}-(?<epochMs>\d{13})-\d+\.mp4$/';
    private const int MAX_LENGTH_MS = 31 * 60 * 1000;
    private const int PREVIOUS_FOLDER_SECONDS = 35 * 60;

    public function __construct(
        #[Autowire(env: 'resolve:RECORDINGS_DIR')]
        private string $recordingsDir,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->recordingsDir && is_dir($this->recordingsDir);
    }

    public function locate(string $recordingFolder, DateTimeImmutable $at): ?Recording
    {
        $atMs = (int) $at->format('Uv');
        $best = null;
        foreach ($this->folderCandidates($recordingFolder, $at) as $folder) {
            foreach ($this->recordingsIn($folder) as $recording) {
                if ($recording->startedAtMs > $atMs || $atMs - $recording->startedAtMs > self::MAX_LENGTH_MS) {
                    continue;
                }

                if (!$best instanceof Recording || $recording->startedAtMs > $best->startedAtMs) {
                    $best = $recording;
                }
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private function folderCandidates(string $recordingFolder, DateTimeImmutable $at): array
    {
        $folders = [];
        foreach ([$at, $at->modify(\sprintf('-%d seconds', self::PREVIOUS_FOLDER_SECONDS))] as $moment) {
            $folders[] = \sprintf(
                '%s/%s/%s',
                rtrim($this->recordingsDir, '/'),
                $recordingFolder,
                $this->homeTimeZone->toLocal($moment)->format('YmdA'),
            );
        }

        return array_values(array_unique($folders));
    }

    /**
     * @return iterable<Recording>
     */
    private function recordingsIn(string $folder): iterable
    {
        $files = is_dir($folder) ? scandir($folder) : false;
        if (false === $files) {
            return;
        }

        foreach ($files as $file) {
            if (1 === preg_match(self::FILE_PATTERN, $file, $matches)) {
                yield new Recording($folder.'/'.$file, (int) $matches['epochMs']);
            }
        }
    }
}
