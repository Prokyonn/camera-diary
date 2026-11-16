<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Domain\Model;

use App\Domain\Model\Camera;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A camera push Home Assistant sent, handed over through an input_text so the AI verdict can replace it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'home_assistant_push')]
#[ORM\UniqueConstraint(name: 'home_assistant_push_tag', columns: ['tag'])]
#[ORM\Index(name: 'home_assistant_push_camera_pushed_at', columns: ['camera', 'pushed_at'])]
class PushHandover
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(
        #[ORM\Column(length: 32)]
        private string $camera,
        #[ORM\Column]
        private DateTimeImmutable $pushedAt,
        #[ORM\Column]
        private bool $withMuteActions,
        #[ORM\Column(length: 120)]
        private string $tag,
        #[ORM\Column(name: 'notification_group', length: 120)]
        private string $group,
        #[ORM\Column(length: 255)]
        private string $fileBase,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(length: 255)]
        private string $message,
    ) {
    }

    /**
     * Parses "camera|epoch|mute 0/1|tag|group|file base|title|message"; the message may contain "|".
     */
    public static function fromHandoverValue(string $value): ?self
    {
        $parts = explode('|', $value, 8);
        if (8 !== \count($parts)) {
            return null;
        }

        [$camera, $epoch, $mute, $tag, $group, $fileBase, $title, $message] = $parts;
        $valid = Camera::isValidSlug($camera)
            && ctype_digit($epoch)
            && \in_array($mute, ['0', '1'], true)
            && '' !== $tag
            && 1 === preg_match('~^[\w-]+(/[\w.-]+)*$~', $fileBase)
            && !str_contains($fileBase, '..');

        return $valid ? new self($camera, new DateTimeImmutable('@'.$epoch), '1' === $mute, $tag, $group, $fileBase, $title, $message) : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCamera(): string
    {
        return $this->camera;
    }

    public function getPushedAt(): DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function hasMuteActions(): bool
    {
        return $this->withMuteActions;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Path under Home Assistant's /media/local without the extension: .jpg is the snapshot, .mp4 the clip.
     */
    public function getFileBase(): string
    {
        return $this->fileBase;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isUpdated(): bool
    {
        return $this->updatedAt instanceof DateTimeImmutable;
    }

    public function markUpdated(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
