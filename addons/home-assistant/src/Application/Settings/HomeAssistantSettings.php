<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Settings;

use App\Domain\Model\DetectionType;

final readonly class HomeAssistantSettings
{
    /**
     * @param list<CameraMapping> $cameras
     * @param list<SignalMapping> $signals
     */
    public function __construct(
        public array $cameras = [],
        public array $signals = [],
        public ?PushSettings $push = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->cameras && [] === $this->signals;
    }

    public function camera(string $slug): ?CameraMapping
    {
        foreach ($this->cameras as $camera) {
            if ($camera->slug === $slug) {
                return $camera;
            }
        }

        return null;
    }

    public function signal(string $entityId): ?SignalMapping
    {
        foreach ($this->signals as $signal) {
            if ($signal->entityId === $entityId) {
                return $signal;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{CameraMapping, DetectionType}> entity id => camera and detection type
     */
    public function detectionSensors(): array
    {
        $sensors = [];
        foreach ($this->cameras as $camera) {
            foreach ($camera->detections as $type) {
                $sensors[$camera->sensorEntityId($type)] = [$camera, $type];
            }
        }

        return $sensors;
    }
}
