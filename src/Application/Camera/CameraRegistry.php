<?php

declare(strict_types=1);

namespace App\Application\Camera;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;

/**
 * Cameras register themselves with their first event.
 */
final readonly class CameraRegistry
{
    public function __construct(
        private CameraRepositoryInterface $cameras,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    public function getOrCreate(string $slug, ?string $name = null): Camera
    {
        $camera = $this->cameras->findOneBy(['slug' => $slug]);
        if ($camera instanceof Camera) {
            return $camera;
        }

        $camera = new Camera($slug, $name);
        $this->cameras->add($camera);
        $this->unitOfWork->flush();

        return $camera;
    }
}
