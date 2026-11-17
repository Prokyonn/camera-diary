<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Application\Settings\VocabularyKind;
use Attribute;

/**
 * Restricts a string field of an AI output to the names of a configured vocabulary, see VocabularyResponseFormatFactory.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class VocabularyChoice
{
    /**
     * @param array<string, string> $extra names allowed besides the vocabulary => what they mean
     */
    public function __construct(
        public VocabularyKind $vocabulary,
        public array $extra = [],
    ) {
    }
}
