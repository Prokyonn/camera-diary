<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * A camera that sends events; created on its first event and identified by its slug.
 */
#[ORM\Entity]
class Camera
{
    public const string SLUG_PATTERN = '[a-z0-9][a-z0-9_-]{0,31}';

    #[ORM\Column(length: 120)]
    private string $name;

    /** What the camera sees; gives the AI the context to tell real events from false alarms. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scene = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $slug,
        ?string $name = null,
    ) {
        if (!self::isValidSlug($slug)) {
            throw new InvalidArgumentException(\sprintf('Invalid camera slug "%s": use lower-case letters, digits, "-" and "_".', $slug));
        }

        $this->name = $name ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
    }

    public static function isValidSlug(string $slug): bool
    {
        return 1 === preg_match('/^'.self::SLUG_PATTERN.'$/', $slug);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getScene(): ?string
    {
        return $this->scene;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidArgumentException('A camera name must not be empty.');
        }

        $this->name = $name;
    }

    public function describeScene(?string $scene): void
    {
        $scene = null !== $scene ? trim($scene) : null;
        $this->scene = '' === $scene ? null : $scene;
    }
}
