<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class CameraEventNotFoundException extends RuntimeException
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(public readonly array $filters)
    {
        parent::__construct(\sprintf('No camera event matches the filters "%s".', implode('", "', array_keys($filters))));
    }
}
