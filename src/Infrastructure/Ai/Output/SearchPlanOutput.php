<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Application\Settings\VocabularyKind;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output: how to search the event index for a question.
 */
final readonly class SearchPlanOutput
{
    /**
     * @param list<string> $cameras
     * @param list<string> $subjects
     * @param list<string> $individuals
     */
    public function __construct(
        #[Schema(description: 'English keywords for what subjects, individuals, cameras, time and side cannot express, e.g. colours, clothing or actions ("grey", "parcel"). Empty if those already say everything.')]
        public string $keywords,
        #[Schema(description: 'What the question is about, e.g. person for people, visitors or anyone; empty for anything')]
        #[VocabularyChoice(VocabularyKind::Subjects)]
        public array $subjects,
        #[Schema(description: 'Known individuals the question names; empty for anyone')]
        #[VocabularyChoice(VocabularyKind::Individuals)]
        public array $individuals,
        #[Schema(description: 'Slugs of the cameras the question is about; empty for all cameras')]
        public array $cameras,
        #[Schema(description: 'Start of the time range as ISO 8601 with offset, or empty for no limit')]
        public string $from,
        #[Schema(description: 'End of the time range as ISO 8601 with offset, or empty for no limit')]
        public string $to,
        #[Schema(description: 'Restrict to our property or to outside of it (behind the fence, on the street); any if the question does not say')]
        public SideFilter $side,
        #[Schema(description: 'True only if the question asks about false alarms too')]
        public bool $includeFalseAlarms,
    ) {
    }
}
