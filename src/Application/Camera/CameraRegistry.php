<?php

declare(strict_types=1);

namespace App\Application\Camera;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Cameras register themselves with their first event. A lock per camera keeps two first events that arrive at once
 * (the hook and the add-on's import, or two hook calls) from both creating it.
 */
final readonly class CameraRegistry
{
    public function __construct(
        private CameraRepositoryInterface $cameras,
        private UnitOfWorkInterface $unitOfWork,
        private LockFactory $lockFactory,
    ) {
    }

    public function getOrCreate(string $slug, ?string $name = null): Camera
    {
        $camera = $this->cameras->findOneBy(['slug' => $slug]);
        if ($camera instanceof Camera) {
            return $camera;
        }

        $lock = $this->lockFactory->createLock('camera-'.$slug);
        $lock->acquire(true);

        try {
            $camera = $this->cameras->findOneBy(['slug' => $slug]);
            if ($camera instanceof Camera) {
                return $camera;
            }

            $camera = new Camera($slug, $name);
            $this->cameras->add($camera);
            $this->unitOfWork->flush();

            return $camera;
        } finally {
            $lock->release();
        }
    }
}
