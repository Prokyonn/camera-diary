<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Picks the client for AI_PLATFORM; only that platform is ever instantiated.
 */
final readonly class StructuredClientFactory
{
    public const array PLATFORMS = ['gemini', 'ollama', 'claude_code', 'codex'];

    public function __construct(
        #[AutowireLocator([
            'gemini' => new Autowire(service: 'ai.platform.gemini'),
            'ollama' => new Autowire(service: 'ai.platform.ollama'),
            'claude_code' => new Autowire(service: 'app.ai.platform.claude_code'),
            'codex' => new Autowire(service: 'app.ai.platform.codex'),
        ])]
        private ContainerInterface $platforms,
        private OutputDecoder $decoder,
        #[Autowire(env: 'AI_PLATFORM')]
        private string $platform,
        #[Autowire(env: 'AI_MODEL')]
        private string $model,
    ) {
    }

    public function create(): StructuredClientInterface
    {
        if (!\in_array($this->platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException(\sprintf('AI_PLATFORM must be one of "%s", "%s" given.', implode('", "', self::PLATFORMS), $this->platform));
        }

        $model = $this->model;
        if ('' === $model) {
            throw new InvalidArgumentException('AI_MODEL must not be empty.');
        }

        $platform = $this->platforms->get($this->platform);
        if (!$platform instanceof PlatformInterface) {
            throw new InvalidArgumentException(\sprintf('The "%s" platform is not available.', $this->platform));
        }

        return match ($this->platform) {
            'claude_code' => new ClaudeCodeStructuredClient($platform, $model, $this->decoder),
            'codex' => new CodexStructuredClient($platform, $model, $this->decoder),
            default => new PlatformStructuredClient($platform, $model),
        };
    }
}
