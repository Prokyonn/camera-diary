<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Application\Settings\VocabularyKind;
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
        #[Schema(description: 'One or two neutral sentences in English: what is visible, colours, clothing, what the subject does. No guessed names.', maxLength: 400)]
        public string $summary,
        #[Schema(description: 'true only if the event matches the rule for unusual events in the instructions')]
        public bool $unusual,
    ) {
    }
}
