<?php

declare(strict_types=1);

namespace App\Domain\Model;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;

/**
 * One thing a camera saw: created with its frames by the ingest hook, or opened by an add-on that adds detections
 * and frames later.
 */
#[ORM\Entity]
#[ORM\Index(name: 'camera_event_camera_started', columns: ['camera', 'started_at'])]
#[ORM\Index(name: 'camera_event_status_started', columns: ['status', 'started_at'])]
#[ORM\UniqueConstraint(name: 'camera_event_external_id', columns: ['source', 'external_id'])]
class CameraEvent
{
    public const int MAX_HINTS = 20;
    public const int MAX_HINT_LENGTH = 300;

    /** The answer, or correction, that the subject is none of the known individuals. */
    public const string NO_KNOWN_INDIVIDUAL = 'unknown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private DateTimeImmutable $endedAt;

    /** @var list<string> names from the detection types vocabulary */
    #[ORM\Column(type: Types::JSON)]
    private array $detectionTypes;

    #[ORM\Column]
    private int $detectionCount = 1;

    /** @var list<string> context from the sender, e.g. "07:16:20 Front door opened" */
    #[ORM\Column(type: Types::JSON)]
    private array $hints = [];

    #[ORM\Column(length: 16)]
    private EventStatus $status = EventStatus::Pending;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $nextAttemptAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $framePaths = [];

    #[ORM\Column(nullable: true)]
    private ?int $frameWidth = null;

    #[ORM\Column(nullable: true)]
    private ?int $frameHeight = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $framesRemovedAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?Verdict $verdict = null;

    /** a name from the false-alarm causes vocabulary; null for real events */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $falseAlarmCause = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?Side $side = null;

    /** a name from the subjects vocabulary */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $subject = null;

    /** the name of a known individual the subject is, e.g. the household's cat */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $individual = null;

    /** set by hand: the name of a known individual, or NO_KNOWN_INDIVIDUAL */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $individualOverride = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $unusual = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $describedAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?Verdict $verdictOverride = null;

    /**
     * @param list<string> $detectionTypes names from the detection types vocabulary
     * @param bool         $open           an open event still takes detections and is not described yet
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'camera', referencedColumnName: 'slug', nullable: false)]
        private Camera $camera,
        #[ORM\Column]
        private DateTimeImmutable $startedAt,
        array $detectionTypes,
        #[ORM\Column(length: 32)]
        private string $source,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $externalId = null,
        ?DateTimeImmutable $endedAt = null,
        #[ORM\Column]
        private bool $open = false,
    ) {
        if ($endedAt instanceof DateTimeImmutable && $endedAt < $startedAt) {
            throw new LogicException('An event cannot end before it starts.');
        }

        $this->endedAt = $endedAt ?? $startedAt;
        $this->detectionTypes = array_values(array_unique(array_map($this->termName(...), $detectionTypes)));
    }

    public function getId(): int
    {
        return $this->id ?? throw new LogicException('The event has not been saved yet.');
    }

    public function getCamera(): Camera
    {
        return $this->camera;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * @return list<string>
     */
    public function getDetectionTypes(): array
    {
        return $this->detectionTypes;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * @return list<string>
     */
    public function getHints(): array
    {
        return $this->hints;
    }

    public function getDetectionCount(): int
    {
        return $this->detectionCount;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    /**
     * @return list<string>
     */
    public function getFramePaths(): array
    {
        return $this->framePaths;
    }

    public function getFrameWidth(): ?int
    {
        return $this->frameWidth;
    }

    public function getFrameHeight(): ?int
    {
        return $this->frameHeight;
    }

    public function getFramesRemovedAt(): ?DateTimeImmutable
    {
        return $this->framesRemovedAt;
    }

    public function getVerdict(): ?Verdict
    {
        return $this->verdict;
    }

    public function getFalseAlarmCause(): ?string
    {
        return $this->falseAlarmCause;
    }

    public function getSide(): ?Side
    {
        return $this->side;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getIndividual(): ?string
    {
        return $this->individual;
    }

    public function getIndividualOverride(): ?string
    {
        return $this->individualOverride;
    }

    /**
     * The individual set by hand, else the AI's; null when the subject is none of the known individuals.
     */
    public function getEffectiveIndividual(): ?string
    {
        if (self::NO_KNOWN_INDIVIDUAL === $this->individualOverride) {
            return null;
        }

        return $this->individualOverride ?? $this->individual;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function isUnusual(): bool
    {
        return $this->unusual;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getDescribedAt(): ?DateTimeImmutable
    {
        return $this->describedAt;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getVerdictOverride(): ?Verdict
    {
        return $this->verdictOverride;
    }

    public function getEffectiveVerdict(): ?Verdict
    {
        return $this->verdictOverride ?? $this->verdict;
    }

    public function isFalseAlarm(): bool
    {
        return Verdict::FalseAlarm === $this->getEffectiveVerdict();
    }

    public function canTakeDetections(): bool
    {
        return $this->open && EventStatus::Pending === $this->status && [] === $this->framePaths;
    }

    /**
     * @param string $type a name from the detection types vocabulary
     */
    public function addDetection(string $type, DateTimeImmutable $detectedAt): void
    {
        if (!$this->canTakeDetections()) {
            throw new LogicException('Detections can only join an open, pending event without frames.');
        }

        if ($detectedAt > $this->endedAt) {
            $this->endedAt = $detectedAt;
        }

        $type = $this->termName($type);
        if (!\in_array($type, $this->detectionTypes, true)) {
            $this->detectionTypes[] = $type;
        }

        ++$this->detectionCount;
    }

    /**
     * Closed events get their frames and description; no detection joins them anymore.
     */
    public function close(): void
    {
        $this->open = false;
    }

    public function addHint(string $hint): void
    {
        $hint = mb_substr(trim($hint), 0, self::MAX_HINT_LENGTH);
        if ('' === $hint || \in_array($hint, $this->hints, true) || \count($this->hints) >= self::MAX_HINTS) {
            return;
        }

        $this->hints[] = $hint;
    }

    /**
     * @param non-empty-list<string> $framePaths
     */
    public function recordFrames(array $framePaths, int $width, int $height): void
    {
        $this->framePaths = $framePaths;
        $this->frameWidth = $width;
        $this->frameHeight = $height;
    }

    public function describe(
        Verdict $verdict,
        ?string $falseAlarmCause,
        Side $side,
        string $subject,
        ?string $individual,
        string $summary,
        bool $unusual,
        string $model,
        DateTimeImmutable $describedAt,
    ): void {
        $this->verdict = $verdict;
        $this->falseAlarmCause = Verdict::FalseAlarm === $verdict && null !== $falseAlarmCause ? $this->termName($falseAlarmCause) : null;
        $this->side = $side;
        $this->subject = $this->termName($subject);
        $this->individual = null === $individual ? null : $this->termName($individual);
        $this->summary = $summary;
        $this->unusual = $unusual;
        $this->model = $model;
        $this->describedAt = $describedAt;
        $this->status = EventStatus::Described;
        $this->nextAttemptAt = null;
    }

    public function retryAt(DateTimeImmutable $nextAttemptAt): void
    {
        ++$this->attempts;
        $this->nextAttemptAt = $nextAttemptAt;
    }

    public function markNoFootage(): void
    {
        $this->status = EventStatus::NoFootage;
        $this->nextAttemptAt = null;
    }

    public function markFailed(): void
    {
        $this->status = EventStatus::Failed;
        $this->nextAttemptAt = null;
    }

    /**
     * @param ?string $individualOverride the name of a known individual, NO_KNOWN_INDIVIDUAL, or null for the AI's answer
     */
    public function relabel(?string $label, ?Verdict $verdictOverride, ?string $individualOverride): void
    {
        $label = null !== $label ? trim($label) : null;
        $this->label = '' === $label ? null : $label;
        $this->verdictOverride = $verdictOverride;
        $this->individualOverride = null === $individualOverride ? null : $this->termName($individualOverride);
    }

    /**
     * @return list<string> the frame paths that were attached to the event
     */
    public function removeFrames(DateTimeImmutable $removedAt): array
    {
        $framePaths = $this->framePaths;
        $this->framePaths = [];
        $this->framesRemovedAt = $removedAt;

        return $framePaths;
    }

    private function termName(string $name): string
    {
        if (!TermName::isValid($name)) {
            throw new InvalidArgumentException(\sprintf('"%s" is no valid name for a detection type, subject, cause or individual.', $name));
        }

        return $name;
    }
}
