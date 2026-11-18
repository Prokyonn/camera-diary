<?php

declare(strict_types=1);

namespace App\Domain\Model;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * The AI's short summary of one local day, written on request and kept, so the timeline shows it without a new call.
 */
#[ORM\Entity]
class DayDigest
{
    public function __construct(
        /** the local date, Y-m-d */
        #[ORM\Id]
        #[ORM\Column(length: 10)]
        private string $day,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        /** how many events the summary covers; a different count means it is out of date */
        #[ORM\Column]
        private int $eventCount,
        #[ORM\Column(length: 64)]
        private string $model,
        #[ORM\Column]
        private DateTimeImmutable $writtenAt,
    ) {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new InvalidArgumentException(\sprintf('"%s" is no date, use Y-m-d.', $day));
        }
    }

    public function rewrite(string $text, int $eventCount, string $model, DateTimeImmutable $writtenAt): void
    {
        $this->text = $text;
        $this->eventCount = $eventCount;
        $this->model = $model;
        $this->writtenAt = $writtenAt;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getEventCount(): int
    {
        return $this->eventCount;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getWrittenAt(): DateTimeImmutable
    {
        return $this->writtenAt;
    }
}
