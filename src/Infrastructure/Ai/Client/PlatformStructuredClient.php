<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * API platforms with native image input and structured output, e.g. Gemini and Ollama.
 */
final readonly class PlatformStructuredClient implements StructuredClientInterface
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        private string $model,
    ) {
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function request(Prompt $prompt, string $outputClass): object
    {
        $content = array_map(
            static fn (string|PromptImage $part): Text|Image => $part instanceof PromptImage ? Image::fromFile($part->path) : new Text($part),
            $prompt->parts,
        );

        try {
            return $this->platform
                ->invoke($this->model, new MessageBag(Message::forSystem($prompt->instructions), Message::ofUser(...$content)), [
                    PlatformSubscriber::RESPONSE_FORMAT => $outputClass,
                ])
                ->asObject($outputClass);
        } catch (PlatformException|HttpException $exception) {
            throw new AiRequestFailedException($exception->getMessage(), 0, $exception);
        }
    }
}
