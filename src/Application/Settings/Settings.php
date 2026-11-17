<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * Everything one installation may change; defaults apply to whatever its settings file leaves out.
 */
final readonly class Settings
{
    /**
     * @param list<Individual> $individuals
     */
    public function __construct(
        public Vocabulary $detectionTypes,
        public Vocabulary $subjects,
        public Vocabulary $falseAlarmCauses,
        public array $individuals,
        public AiSettings $ai,
        public DescriptionSettings $description,
        public QuestionSettings $questions,
        public MaintenanceSettings $maintenance,
    ) {
    }

    public function vocabulary(VocabularyKind $kind): Vocabulary
    {
        return match ($kind) {
            VocabularyKind::DetectionTypes => $this->detectionTypes,
            VocabularyKind::Subjects => $this->subjects,
            VocabularyKind::FalseAlarmCauses => $this->falseAlarmCauses,
            VocabularyKind::Individuals => new Vocabulary(array_map(
                static fn (Individual $individual): Term => new Term($individual->name, $individual->label, $individual->description),
                $this->individuals,
            )),
        };
    }

    public function individual(string $name): ?Individual
    {
        foreach ($this->individuals as $individual) {
            if ($individual->name === $name) {
                return $individual;
            }
        }

        return null;
    }

    /**
     * How an event's subject reads: the known individual ("Luna"), "Other cat" for a subject that has known
     * individuals, or else the subject's label.
     */
    public function subjectLabel(string $subject, ?string $individual): string
    {
        if (null !== $individual) {
            return $this->vocabulary(VocabularyKind::Individuals)->label($individual);
        }

        $label = $this->subjects->label($subject);
        foreach ($this->individuals as $known) {
            if ($known->subject === $subject) {
                return 'Other '.lcfirst($label);
            }
        }

        return $label;
    }
}
