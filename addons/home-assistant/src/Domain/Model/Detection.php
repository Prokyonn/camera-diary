<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Model;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One detection sensor turning on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'home_assistant_detection')]
#[ORM\UniqueConstraint(name: 'home_assistant_detection_unique', columns: ['camera', 'type', 'detected_at'])]
#[ORM\Index(name: 'home_assistant_detection_detected_at', columns: ['detected_at'])]
class Detection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CameraEvent $event = null;

    public function __construct(
        #[ORM\Column(length: 32)]
        private string $camera,
        #[ORM\Column(length: 16)]
        private DetectionType $type,
        #[ORM\Column]
        private DateTimeImmutable $detectedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCamera(): string
    {
        return $this->camera;
    }

    public function getType(): DetectionType
    {
        return $this->type;
    }

    public function getDetectedAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getEvent(): ?CameraEvent
    {
        return $this->event;
    }

    public function assignTo(CameraEvent $event): void
    {
        $this->event = $event;
    }

    public function key(): string
    {
        return self::keyOf($this->camera, $this->type, $this->detectedAt);
    }

    public static function keyOf(string $camera, DetectionType $type, DateTimeImmutable $detectedAt): string
    {
        return \sprintf('%s|%s|%s', $camera, $type->value, $detectedAt->format('U'));
    }
}
