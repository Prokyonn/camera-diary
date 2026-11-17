<?php

declare(strict_types=1);

namespace App\Application\Individual;

/**
 * The reference photos added in the app, per known individual.
 */
interface ReferencePhotoStoreInterface
{
    /**
     * Stores the image, or the cropped part of it, as a new reference photo.
     *
     * @throws InvalidReferencePhotoException if the image cannot be read
     */
    public function add(string $individual, string $imagePath, ?CropBox $crop = null): ReferencePhoto;

    /**
     * @return list<ReferencePhoto> newest first
     */
    public function findBy(string $individual): array;

    public function remove(string $individual, string $storedId): void;
}
