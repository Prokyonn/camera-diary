<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class AskedQuestionNotFoundException extends RuntimeException
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(public readonly array $filters)
    {
        parent::__construct(\sprintf('No asked question matches the filters "%s".', implode('", "', array_keys($filters))));
    }
}
