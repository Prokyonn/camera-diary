<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

/**
 * Provider-neutral request: instructions plus the user's text and images, in order.
 */
final readonly class Prompt
{
    /**
     * @param list<string|PromptImage> $parts
     */
    public function __construct(
        public string $instructions,
        public array $parts,
    ) {
    }

    /**
     * @return list<string>
     */
    public function imagePaths(): array
    {
        $paths = [];
        foreach ($this->parts as $part) {
            if ($part instanceof PromptImage) {
                $paths[] = $part->path;
            }
        }

        return $paths;
    }
}
