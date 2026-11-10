<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai\Client;

use App\Infrastructure\Ai\Client\ClaudeCodeStructuredClient;
use App\Infrastructure\Ai\Client\CodexStructuredClient;
use App\Infrastructure\Ai\Client\OutputDecoder;
use App\Infrastructure\Ai\Client\PlatformStructuredClient;
use App\Infrastructure\Ai\Client\StructuredClientFactory;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(StructuredClientFactory::class)]
final class StructuredClientFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string<StructuredClientInterface>, string}>
     */
    public static function platforms(): iterable
    {
        yield 'gemini' => ['gemini', PlatformStructuredClient::class, 'gemini-flash-latest'];
        yield 'vertexai' => ['vertexai', PlatformStructuredClient::class, 'gemini-2.5-flash'];
        yield 'ollama' => ['ollama', PlatformStructuredClient::class, 'qwen2.5vl:7b'];
        yield 'claude_code' => ['claude_code', ClaudeCodeStructuredClient::class, 'claude-code/sonnet'];
        yield 'codex' => ['codex', CodexStructuredClient::class, 'codex/gpt-6.1-sol'];
    }

    /**
     * @param class-string<StructuredClientInterface> $clientClass
     */
    #[DataProvider('platforms')]
    public function testCreatesTheClientOfThePlatform(string $platform, string $clientClass, string $modelName): void
    {
        $model = explode('/', $modelName)[1] ?? $modelName;
        $client = $this->factory($platform, $model)->create();

        self::assertInstanceOf($clientClass, $client);
        self::assertSame($modelName, $client->modelName());
    }

    public function testRejectsAnUnknownPlatform(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory('openai', 'gpt-5')->create();
    }

    public function testRejectsAnEmptyModel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory('gemini', '')->create();
    }

    private function factory(string $platform, string $model): StructuredClientFactory
    {
        $platforms = [];
        foreach (StructuredClientFactory::PLATFORMS as $name) {
            $platforms[$name] = static fn (): Platform => MockPlatformFactory::createPlatform('{}');
        }

        return new StructuredClientFactory(new ServiceLocator($platforms), new OutputDecoder(), $platform, $model);
    }
}
