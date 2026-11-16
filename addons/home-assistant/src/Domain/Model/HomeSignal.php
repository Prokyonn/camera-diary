<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Domain\Model;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A state change of a context entity, e.g. a cat flap or a door.
 */
#[ORM\Entity]
#[ORM\Table(name: 'home_assistant_signal')]
#[ORM\UniqueConstraint(name: 'home_assistant_signal_unique', columns: ['entity_id', 'state', 'changed_at'])]
#[ORM\Index(name: 'home_assistant_signal_changed_at', columns: ['changed_at'])]
class HomeSignal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $entityId,
        #[ORM\Column(length: 64)]
        private string $state,
        #[ORM\Column]
        private DateTimeImmutable $changedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function key(): string
    {
        return self::keyOf($this->entityId, $this->state, $this->changedAt);
    }

    public static function keyOf(string $entityId, string $state, DateTimeImmutable $changedAt): string
    {
        return \sprintf('%s|%s|%s', $entityId, $state, $changedAt->format('U'));
    }
}
