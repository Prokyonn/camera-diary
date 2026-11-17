<?php

declare(strict_types=1);

namespace App\Application\Individual;

use App\Application\Settings\Individual;

/**
 * The reference photos of one known individual that go with an AI request.
 */
final readonly class IndividualReferences
{
    /**
     * @param non-empty-list<string> $photos absolute paths
     */
    public function __construct(
        public Individual $individual,
        public array $photos,
    ) {
    }
}
