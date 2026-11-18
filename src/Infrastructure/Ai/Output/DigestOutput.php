<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output: the summary of one day.
 */
final readonly class DigestOutput
{
    public function __construct(
        #[Schema(description: 'Two to four plain sentences in English without Markdown', maxLength: 700)]
        public string $text,
    ) {
    }
}
