<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output: the answer and the events it relies on.
 */
final readonly class AnswerOutput
{
    /**
     * @param list<int> $eventIds
     */
    public function __construct(
        #[Schema(description: 'Short answer in plain text without Markdown, in the language of the question, with local dates and times')]
        public string $text,
        #[Schema(description: 'Ids of the events the answer relies on, most relevant first')]
        public array $eventIds,
    ) {
    }
}
