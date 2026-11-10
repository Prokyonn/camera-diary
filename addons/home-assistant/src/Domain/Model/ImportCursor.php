<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Domain\Model;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'home_assistant_import_cursor')]
class ImportCursor
{
    public const string HOME_ASSISTANT = 'home_assistant';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $name,
        #[ORM\Column]
        private DateTimeImmutable $importedUntil,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getImportedUntil(): DateTimeImmutable
    {
        return $this->importedUntil;
    }

    public function advanceTo(DateTimeImmutable $importedUntil): void
    {
        if ($importedUntil > $this->importedUntil) {
            $this->importedUntil = $importedUntil;
        }
    }
}
