<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;

/**
 * Sends a prompt with images to the configured model and maps its JSON answer onto an output class.
 */
interface StructuredClientInterface
{
    public function modelName(): string;

    /**
     * @template T of object
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws AiRequestFailedException
     */
    public function request(Prompt $prompt, string $outputClass): object;
}
