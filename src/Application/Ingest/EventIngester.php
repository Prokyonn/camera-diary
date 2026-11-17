<?php

declare(strict_types=1);

namespace App\Application\Ingest;

use App\Application\Camera\CameraRegistry;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameExtractorInterface;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The hook any application can use: stores an event with frames cut from its video or images. The worker
 * describes it on its next run.
 */
final readonly class EventIngester
{
    /** Share of the video length at which frames are taken. */
    public const array VIDEO_POSITIONS = [0.25, 0.5, 0.75];

    public function __construct(
        private CameraRegistry $cameras,
        private CameraEventRepositoryInterface $events,
        private FrameExtractorInterface $extractor,
        private FrameStorageInterface $frameStorage,
        private EventIndexerInterface $indexer,
        private UnitOfWorkInterface $unitOfWork,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @throws InvalidIngestException
     */
    public function ingest(IngestRequest $request): IngestResult
    {
        $this->assertValid($request);

        if (null !== $request->externalId) {
            $existing = $this->events->findOneBy(['source' => $request->source, 'externalId' => $request->externalId]);
            if ($existing instanceof CameraEvent) {
                return new IngestResult($existing, false);
            }
        }

        $sources = $this->frameSources($request);

        // Open while the frames are written, so the worker does not pick it up half done.
        $utc = new DateTimeZone('UTC');
        $event = new CameraEvent(
            $this->cameras->getOrCreate($request->cameraSlug, $request->cameraName),
            $request->startedAt->setTimezone($utc),
            $request->detectionTypes,
            $request->source,
            $request->externalId,
            $request->endedAt?->setTimezone($utc),
            open: true,
        );
        foreach ($request->hints as $hint) {
            $event->addHint($hint);
        }

        $this->events->add($event);
        $this->unitOfWork->flush();

        try {
            $this->storeFrames($event, $sources);
        } catch (FrameExtractionFailedException $exception) {
            $this->frameStorage->remove(array_map(fn (int $index): string => $this->frameStorage->relativePathFor($event, $index), array_keys($sources)));
            $this->events->remove($event);
            $this->unitOfWork->flush();

            throw new InvalidIngestException('The media could not be read: '.$exception->getMessage(), 0, $exception);
        }

        $event->close();
        $this->unitOfWork->flush();
        $this->indexer->index($event);

        return new IngestResult($event, true);
    }

    private function assertValid(IngestRequest $request): void
    {
        if (!Camera::isValidSlug($request->cameraSlug)) {
            throw new InvalidIngestException(\sprintf('Invalid camera "%s": use lower-case letters, digits, "-" and "_", at most 32 characters.', $request->cameraSlug));
        }

        if ((null === $request->videoPath) === ([] === $request->imagePaths)) {
            throw new InvalidIngestException('Send either one video or up to three images.');
        }

        if (\count($request->imagePaths) > IngestRequest::MAX_IMAGES) {
            throw new InvalidIngestException(\sprintf('Send at most %d images.', IngestRequest::MAX_IMAGES));
        }

        if ($request->endedAt instanceof DateTimeImmutable && $request->endedAt < $request->startedAt) {
            throw new InvalidIngestException('The event cannot end before it starts.');
        }

        $detectionTypes = $this->settings->settings()->detectionTypes;
        foreach ($request->detectionTypes as $type) {
            if (!$detectionTypes->has($type)) {
                throw new InvalidIngestException(\sprintf('Unknown detection type "%s". Known: %s.', $type, implode(', ', $detectionTypes->names())));
            }
        }
    }

    /**
     * @return non-empty-list<array{string, float|null}> source path and video position per frame
     */
    private function frameSources(IngestRequest $request): array
    {
        if (null === $request->videoPath) {
            $images = array_map(static fn (string $path): array => [$path, null], $request->imagePaths);
            if ([] === $images) {
                throw new InvalidIngestException('Send either one video or up to three images.');
            }

            return $images;
        }

        $duration = $this->extractor->duration($request->videoPath)
            ?? throw new InvalidIngestException('The video could not be read.');

        return array_map(
            static fn (float $position): array => [$request->videoPath, round($duration * $position, 3)],
            self::VIDEO_POSITIONS,
        );
    }

    /**
     * @param non-empty-list<array{string, float|null}> $sources
     *
     * @throws FrameExtractionFailedException
     */
    private function storeFrames(CameraEvent $event, array $sources): void
    {
        $paths = [];
        foreach ($sources as $index => [$sourcePath, $atSeconds]) {
            $relativePath = $this->frameStorage->relativePathFor($event, $index);
            $this->extractor->extract($sourcePath, $atSeconds, $this->frameStorage->prepare($relativePath));
            $paths[] = $relativePath;
        }

        $event->recordFrames($paths, ...$this->frameStorage->imageSize($paths[0]));
    }
}
