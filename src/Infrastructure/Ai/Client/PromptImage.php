<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

final readonly class PromptImage
{
    public function __construct(
        public string $path,
    ) {
    }
}
