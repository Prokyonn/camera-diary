<?php

declare(strict_types=1);

namespace App\Application\Individual;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;

/**
 * The reference photos of the known individuals: the settings' ones, plus the ones added in the app from event frames.
 */
final readonly class ReferencePhotos
{
    public function __construct(
        private SettingsProviderInterface $settings,
        private ReferencePhotoStoreInterface $store,
        private FrameStorageInterface $frameStorage,
    ) {
    }

    /**
     * @return list<ReferencePhoto> the settings' photos first, then the added ones, newest first
     */
    public function of(Individual $individual): array
    {
        return [
            ...array_map(static fn (string $path): ReferencePhoto => new ReferencePhoto($path), $individual->photos),
            ...$this->store->findBy($individual->name),
        ];
    }

    /**
     * The photos the AI gets with this event: of the individuals for its detection types, at most
     * ai.reference_photos each.
     *
     * @return list<IndividualReferences>
     */
    public function forEvent(CameraEvent $event): array
    {
        $settings = $this->settings->settings();
        $references = [];
        foreach ($settings->individuals as $individual) {
            if ([] !== $individual->detectionTypes && [] === array_intersect($individual->detectionTypes, $event->getDetectionTypes())) {
                continue;
            }

            $photos = \array_slice(array_map(static fn (ReferencePhoto $photo): string => $photo->path, $this->of($individual)), 0, $settings->ai->referencePhotos);
            if ([] !== $photos) {
                $references[] = new IndividualReferences($individual, $photos);
            }
        }

        return $references;
    }

    /**
     * Stores a frame of the event, or the cropped part of it, as a reference photo of the individual.
     *
     * @throws InvalidReferencePhotoException if the event has no such frame
     */
    public function addFromFrame(CameraEvent $event, int $frameIndex, Individual $individual, ?CropBox $crop): ReferencePhoto
    {
        $relativePath = $event->getFramePaths()[$frameIndex] ?? null;
        if (null === $relativePath) {
            throw new InvalidReferencePhotoException(\sprintf('Event %d has no frame %d.', $event->getId(), $frameIndex));
        }

        return $this->store->add($individual->name, $this->frameStorage->absolutePath($relativePath), $crop);
    }
}
