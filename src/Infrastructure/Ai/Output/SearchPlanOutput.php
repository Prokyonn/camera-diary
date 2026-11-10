<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured output: how to search the event index for a question.
 */
final readonly class SearchPlanOutput
{
    /**
     * @param list<string> $cameras
     */
    public function __construct(
        #[Schema(description: 'English keywords to match against event descriptions and labels, e.g. "grey cat" or "person". Empty if the question only names a time, camera or place.')]
        public string $keywords,
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
