<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Application\Settings\Individual;
use App\Application\Settings\VocabularyKind;
use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output the AI returns for one event.
 */
final readonly class EventDescriptionOutput
{
    /** The false-alarm cause of a real event. */
    public const string NO_FALSE_ALARM = 'none';

    /**
     * @param list<string> $alsoSeen
     */
    public function __construct(
        #[Schema(description: 'real if something relevant is visible, e.g. one of the subjects; false_alarm if nothing relevant is visible')]
        public Verdict $verdict,
        #[Schema(description: 'What caused a false alarm; none for real events')]
        #[VocabularyChoice(VocabularyKind::FalseAlarmCauses, extra: [self::NO_FALSE_ALARM => 'a real event'])]
        public string $falseAlarmCause,
        #[Schema(description: 'Where the subject is: on our property, outside of it (behind the fence, on the street), both, or unclear')]
        public Side $side,
        #[Schema(description: 'The main subject')]
        #[VocabularyChoice(VocabularyKind::Subjects)]
        public string $subject,
        #[Schema(description: 'The known individual the subject clearly matches by its reference photos; unknown if none matches or you are not sure')]
        #[VocabularyChoice(VocabularyKind::Individuals, extra: [Individual::UNKNOWN => 'none of the known individuals, or not sure'])]
        public string $individual,
        #[Schema(description: 'One or two neutral sentences in English: what is visible, colours, clothing, what the subject does. No names, except a known individual you identified.', maxLength: 400)]
        public string $summary,
        #[Schema(description: 'true only if the event matches the rule for unusual events in the instructions')]
        public bool $unusual,
        #[Schema(description: 'How sure you are of the whole answer. high: verdict, subject and, where known individuals of that subject exist, whether it is one of them are all clear. medium: one of them is uncertain, e.g. the subject looks like a known individual but you did not name it, or it is small, dark, blurred, partly hidden or only in one frame. low: you are mostly guessing')]
        public Confidence $confidence = Confidence::High,
        #[Schema(description: 'Other known individuals that are also clearly visible besides the main subject, e.g. the household cat next to a person; empty if none')]
        #[VocabularyChoice(VocabularyKind::Individuals)]
        public array $alsoSeen = [],
    ) {
    }
}
