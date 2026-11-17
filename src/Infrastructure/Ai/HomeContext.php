<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;

/**
 * What every prompt may know about the home: the settings' home context and unusual rule, and the cameras.
 */
final readonly class HomeContext
{
    public function __construct(
        private CameraRepositoryInterface $cameras,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function unusualRule(): string
    {
        return $this->settings->settings()->ai->unusualRule;
    }

    public function notes(): string
    {
        return $this->settings->settings()->ai->homeContext;
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
