<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Domain\Model\FalseAlarmCause;
use App\Domain\Model\Side;
use App\Domain\Model\Subject;
use App\Domain\Model\Verdict;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output the AI returns for one event.
 */
final readonly class EventDescriptionOutput
{
    public function __construct(
        #[Schema(description: 'real if a person, animal or vehicle is visible; false_alarm if nothing relevant is visible')]
        public Verdict $verdict,
        #[Schema(description: 'What caused a false alarm; none for real events')]
        public FalseAlarmCause $falseAlarmCause,
        #[Schema(description: 'Where the subject is: on our property, outside of it (behind the fence, on the street), both, or unclear')]
        public Side $side,
        #[Schema(description: 'The main subject; nothing for false alarms')]
        public Subject $subject,
        #[Schema(description: 'One or two neutral sentences in English: what is visible, colours, clothing, what the subject does. No guessed names.', maxLength: 400)]
        public string $summary,
    ) {
    }
}
