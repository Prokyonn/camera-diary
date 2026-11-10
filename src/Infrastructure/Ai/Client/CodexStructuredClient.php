<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The Codex CLI (ChatGPT subscription). The bridge only passes text, so the images go in as `--image` files and the
 * schema as an `--output-schema` file.
 */
final readonly class CodexStructuredClient implements StructuredClientInterface
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        private OutputDecoder $decoder,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function modelName(): string
    {
        return 'codex/'.$this->model;
    }

    public function request(Prompt $prompt, string $outputClass): object
    {
        $schemaFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'output-schema-', '.json');
        $this->filesystem->dumpFile($schemaFile, $this->decoder->schemaJson($outputClass));

        try {
            $text = $this->platform
                ->invoke($this->model, new MessageBag(Message::forSystem($prompt->instructions), Message::ofUser($this->text($prompt))), [
                    'image' => $prompt->imagePaths(),
                    'output_schema' => $schemaFile,
                    'skip_git_repo_check' => true,
                    'ephemeral' => true,
                    'ignore_user_config' => true,
                ])
                ->asText();
        } catch (PlatformException $exception) {
            throw new AiRequestFailedException($exception->getMessage(), 0, $exception);
        } finally {
            $this->filesystem->remove($schemaFile);
        }

        return $this->decoder->decode($text, $outputClass);
    }

    private function text(Prompt $prompt): string
    {
        $lines = [];
        $imageNumber = 0;
        foreach ($prompt->parts as $part) {
            $lines[] = $part instanceof PromptImage
                ? \sprintf('[Image %d is attached]', ++$imageNumber)
                : $part;
        }

        return implode("\n\n", $lines);
    }
}
