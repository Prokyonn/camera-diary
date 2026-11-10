<?php

declare(strict_types=1);

namespace App\Application\Camera;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\Camera;

final readonly class CameraSettings
{
    public function __construct(
        private CameraRegistry $registry,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    /**
     * Creates the camera if needed; null keeps a value, an empty scene removes it.
     */
    public function update(string $slug, ?string $name = null, ?string $scene = null): Camera
    {
        $camera = $this->registry->getOrCreate($slug);
        if (null !== $name) {
            $camera->rename($name);
        }

        if (null !== $scene) {
            $camera->describeScene($scene);
        }

        $this->unitOfWork->flush();

        return $camera;
    }
}
