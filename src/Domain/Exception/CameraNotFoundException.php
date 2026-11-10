<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class CameraNotFoundException extends RuntimeException
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(public readonly array $filters)
    {
        parent::__construct(\sprintf('No camera matches the filters "%s".', implode('", "', array_keys($filters))));
    }
}
