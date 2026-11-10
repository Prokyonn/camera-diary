<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Client;

use App\Application\Ai\AiRequestFailedException;
use JsonException;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;

/**
 * Structured output for CLI platforms, which only return text: the same strict JSON schema the API platforms get,
 * and the same deserialization of the answer.
 */
final readonly class OutputDecoder
{
    public function __construct(
        private ResponseFormatFactory $responseFormats = new ResponseFormatFactory(),
        private Serializer $serializer = new Serializer(),
    ) {
    }

    /**
     * @param class-string $outputClass
     *
     * @return array<string, mixed>
     */
    public function schema(string $outputClass): array
    {
        return $this->responseFormats->create($outputClass)['json_schema']['schema'];
    }

    /**
     * @param class-string $outputClass
     */
    public function schemaJson(string $outputClass): string
    {
        try {
            return json_encode($this->schema($outputClass), \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiRequestFailedException('The output schema could not be encoded.', 0, $exception);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws AiRequestFailedException
     */
    public function decode(string $text, string $outputClass): object
    {
        $json = trim($text);
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $match)) {
            $json = $match[1];
        }

        try {
            $output = $this->serializer->deserialize($json, $outputClass, 'json');
        } catch (SerializerException $exception) {
            throw new AiRequestFailedException(\sprintf('The answer is no valid %s: %s', $outputClass, $exception->getMessage()), 0, $exception);
        }

        if (!$output instanceof $outputClass) {
            throw new AiRequestFailedException(\sprintf('The answer is no valid %s.', $outputClass));
        }

        return $output;
    }
}
