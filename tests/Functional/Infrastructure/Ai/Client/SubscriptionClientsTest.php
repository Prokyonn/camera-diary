<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\Verdict;
use App\Infrastructure\Ai\Client\ClaudeCodeStructuredClient;
use App\Infrastructure\Ai\Client\CliModelCatalog;
use App\Infrastructure\Ai\Client\CodexStructuredClient;
use App\Infrastructure\Ai\Client\OutputDecoder;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\PromptImage;
use App\Infrastructure\Ai\Output\EventDescriptionOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(ClaudeCodeStructuredClient::class)]
#[CoversClass(CodexStructuredClient::class)]
#[CoversClass(OutputDecoder::class)]
#[CoversClass(CliModelCatalog::class)]
#[CoversClass(Prompt::class)]
final class SubscriptionClientsTest extends TestCase
{
    private const string ANSWER = '{"verdict":"false_alarm","falseAlarmCause":"spider_web","side":"unclear","subject":"nothing","summary":"A web thread swings in front of the lens."}';

    /** @var list<array{input: mixed, options: array<mixed>}> */
    private array $calls = [];

    public function testClaudeCodeReadsTheImagesAsFilesAndGetsTheSchema(): void
    {
        $client = new ClaudeCodeStructuredClient($this->platform(self::ANSWER), 'sonnet', new OutputDecoder());

        $output = $client->request($this->prompt(), EventDescriptionOutput::class);

        self::assertSame(Verdict::FalseAlarm, $output->verdict);
        self::assertSame('claude-code/sonnet', $client->modelName());
        self::assertSame(['Read'], $this->option('tools'));
        self::assertSame(['Read'], $this->option('allowed_tools'));
        self::assertSame(['/data/frames/garden'], $this->option('add_dir'));
        self::assertSame('project', $this->option('setting_sources'), 'The user\'s own settings and hooks stay out.');
        $schema = $this->option('json_schema');
        self::assertIsString($schema);
        self::assertStringContainsString('"additionalProperties":false', $schema);
        $prompt = $this->userText();
        self::assertStringContainsString('[Image 1: /data/frames/garden/1-0.jpg]', $prompt);
        self::assertStringContainsString('Read tool', $prompt);
    }

    public function testClaudeCodeWithoutImagesGetsNoTools(): void
    {
        $client = new ClaudeCodeStructuredClient($this->platform(self::ANSWER), 'haiku', new OutputDecoder());

        $client->request(new Prompt('Plan a search.', ['Where was the cat?']), EventDescriptionOutput::class);

        self::assertSame([], $this->option('tools'));
        self::assertArrayNotHasKey('add_dir', ($this->calls[0] ?? self::fail('The CLI was not called.'))['options']);
    }

    public function testCodexAttachesTheImagesAndAnOutputSchemaFile(): void
    {
        $schemaFile = null;
        $platform = MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input, array $options) use (&$schemaFile): string {
            $this->calls[] = ['input' => $input, 'options' => $options];
            $schemaFile = \is_string($options['output_schema'] ?? null) ? $options['output_schema'] : null;
            self::assertNotNull($schemaFile);
            self::assertFileExists($schemaFile);

            return "```json\n".self::ANSWER."\n```";
        });
        $client = new CodexStructuredClient($platform, 'gpt-5.4-mini', new OutputDecoder());

        $output = $client->request($this->prompt(), EventDescriptionOutput::class);

        self::assertSame('A web thread swings in front of the lens.', $output->summary, 'A code fence around the JSON is fine.');
        self::assertSame(['/data/frames/garden/1-0.jpg', '/data/frames/garden/1-1.jpg'], $this->option('image'));
        self::assertTrue($this->option('skip_git_repo_check'));
        self::assertIsString($schemaFile);
        self::assertFileDoesNotExist($schemaFile, 'The schema file is removed afterwards.');
        self::assertStringContainsString('[Image 2 is attached]', $this->userText());
    }

    public function testRejectsAnAnswerThatDoesNotMatchTheOutput(): void
    {
        $client = new ClaudeCodeStructuredClient($this->platform('{"verdict":"maybe"}'), 'sonnet', new OutputDecoder());

        $this->expectException(AiRequestFailedException::class);

        $client->request($this->prompt(), EventDescriptionOutput::class);
    }

    public function testTheCliCatalogAcceptsAnyModelName(): void
    {
        $model = new CliModelCatalog(Codex::class)->getModel('gpt-6.1-sol');

        self::assertInstanceOf(Codex::class, $model);
        self::assertSame('gpt-6.1-sol', $model->getName());
    }

    private function prompt(): Prompt
    {
        return new Prompt('Classify the event.', [
            'The camera reported a person.',
            new PromptImage('/data/frames/garden/1-0.jpg'),
            new PromptImage('/data/frames/garden/1-1.jpg'),
        ]);
    }

    private function platform(string $answer): Platform
    {
        return MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input, array $options) use ($answer): string {
            $this->calls[] = ['input' => $input, 'options' => $options];

            return $answer;
        });
    }

    private function userText(): string
    {
        return json_encode(($this->calls[0] ?? self::fail('The CLI was not called.'))['input'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function option(string $key): mixed
    {
        $options = ($this->calls[0] ?? self::fail('The CLI was not called.'))['options'];
        self::assertArrayHasKey($key, $options);

        return $options[$key];
    }
}
