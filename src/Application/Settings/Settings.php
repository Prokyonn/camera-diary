<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * Everything one installation may change; defaults apply to whatever its settings file leaves out.
 */
final readonly class Settings
{
    public function __construct(
        public Vocabulary $detectionTypes,
        public Vocabulary $subjects,
        public Vocabulary $falseAlarmCauses,
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
        };
    }
}
