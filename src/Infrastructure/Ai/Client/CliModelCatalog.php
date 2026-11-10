<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Accepts every model name for a CLI platform: which models a subscription may use is up to the CLI, and the
 * bridges' own lists fall behind (a ChatGPT plan, for example, rejects the API model names).
 */
final readonly class CliModelCatalog implements ModelCatalogInterface
{
    private const array CAPABILITIES = [Capability::INPUT_MESSAGES, Capability::INPUT_TEXT, Capability::OUTPUT_TEXT, Capability::OUTPUT_STREAMING];

    /**
     * @param class-string<Model> $modelClass the bridge's model class, e.g. Codex
     */
    public function __construct(
        private string $modelClass,
    ) {
    }

    public function getModel(string $modelName): Model
    {
        return new ($this->modelClass)($modelName, self::CAPABILITIES);
    }

    public function getModels(): array
    {
        return [];
    }
}
