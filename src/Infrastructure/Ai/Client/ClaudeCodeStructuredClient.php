<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * The Claude Code CLI (Claude subscription). The bridge only passes text, so the images are listed as files the
 * model reads with its Read tool, and `--json-schema` enforces the output.
 */
final readonly class ClaudeCodeStructuredClient implements StructuredClientInterface
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        private OutputDecoder $decoder,
    ) {
    }

    public function modelName(): string
    {
        return 'claude-code/'.$this->model;
    }

    public function request(Prompt $prompt, string $outputClass): object
    {
        $imagePaths = $prompt->imagePaths();
        $options = [
            'json_schema' => $this->decoder->schemaJson($outputClass),
            'tools' => [] === $imagePaths ? [] : ['Read'],
            'setting_sources' => 'project',
            'strict_mcp_config' => true,
            'no_session_persistence' => true,
        ];
        if ([] !== $imagePaths) {
            $options['allowed_tools'] = ['Read'];
            $options['add_dir'] = array_values(array_unique(array_map(dirname(...), $imagePaths)));
        }

        try {
            $text = $this->platform
                ->invoke($this->model, new MessageBag(Message::forSystem($prompt->instructions), Message::ofUser($this->text($prompt))), $options)
                ->asText();
        } catch (PlatformException|ProcessException $exception) {
            throw new AiRequestFailedException($exception->getMessage(), 0, $exception);
        }

        return $this->decoder->decode($text, $outputClass);
    }

    private function text(Prompt $prompt): string
    {
        $lines = [];
        $imageNumber = 0;
        foreach ($prompt->parts as $part) {
            $lines[] = $part instanceof PromptImage
                ? \sprintf('[Image %d: %s]', ++$imageNumber, $part->path)
                : $part;
        }

        if ($imageNumber > 0) {
            array_unshift($lines, 'Open every image file listed below with the Read tool before you answer.');
        }

        return implode("\n\n", $lines);
    }
}
