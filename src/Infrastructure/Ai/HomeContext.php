<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What every prompt may know about the home: the free-text AI_HOME_CONTEXT and the cameras.
 */
final readonly class HomeContext
{
    public function __construct(
        private CameraRepositoryInterface $cameras,
        #[Autowire(env: 'AI_HOME_CONTEXT')]
        private string $notes,
    ) {
    }

    public function notes(): string
    {
        return trim($this->notes);
    }

    public function describeCamera(Camera $camera): string
    {
        return trim(\sprintf('%s (%s). %s', $camera->getName(), $camera->getSlug(), $camera->getScene() ?? ''));
    }

    /**
     * @return list<string>
     */
    public function cameraSlugs(): array
    {
        return array_map(static fn (Camera $camera): string => $camera->getSlug(), $this->cameras->findBy());
    }

    public function cameraList(): string
    {
        $lines = array_map(fn (Camera $camera): string => '- '.$this->describeCamera($camera), $this->cameras->findBy([], ['name' => 'asc']));

        return [] === $lines ? '- none yet' : implode("\n", $lines);
    }
}
