<?php

declare(strict_types=1);

namespace App\Domain\Model;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

/**
 * A question asked on the Ask page, with the answer and the events shown with it, so it can be read again.
 */
#[ORM\Entity]
#[ORM\Index(name: 'asked_question_asked_at', columns: ['asked_at'])]
class AskedQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<int> $eventIds the events shown with the answer, cited ones first
     */
    public function __construct(
        #[ORM\Column(type: Types::TEXT)]
        private string $question,
        #[ORM\Column(type: Types::TEXT)]
        private string $answer,
        #[ORM\Column(type: Types::JSON)]
        private array $eventIds,
        #[ORM\Column]
        private DateTimeImmutable $askedAt,
    ) {
    }

    public function getId(): int
    {
        return $this->id ?? throw new LogicException('The question has not been saved yet.');
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    /**
     * @return list<int>
     */
    public function getEventIds(): array
    {
        return $this->eventIds;
    }

    public function getAskedAt(): DateTimeImmutable
    {
        return $this->askedAt;
    }
}
