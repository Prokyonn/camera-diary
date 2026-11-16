<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Settings;

use App\Domain\Model\DetectionType;

/**
 * One camera: its detection sensors in Home Assistant and, optionally, its folder of continuous recordings.
 */
final readonly class CameraMapping
{
    /**
     * @param list<DetectionType> $detections
     */
    public function __construct(
        public string $slug,
        public string $sensorPrefix,
        public array $detections,
        public ?string $name = null,
        public ?string $scene = null,
        public ?string $recordingFolder = null,
    ) {
    }

    public function sensorEntityId(DetectionType $type): string
    {
        return \sprintf('%s_%s', $this->sensorPrefix, $type->value);
    }
}
