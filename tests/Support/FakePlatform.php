<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactoryInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;
use UnderflowException;

/**
 * Stands in for the AI platform in tests: answers with scripted JSON strings and records each call.
 */
final class FakePlatform implements PlatformInterface
{
    /** @var list<string|Throwable> */
    private array $responses = [];

    /** @var list<array{model: string, input: mixed, options: array<mixed>}> */
    private array $calls = [];

    private readonly Platform $platform;

    public function __construct(?ResponseFormatFactoryInterface $responseFormats = null)
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber($responseFormats ?? new ResponseFormatFactory()));

        $this->platform = MockPlatformFactory::createPlatform(
            function (Model $model, array|string|object $input, array $options): string {
                $this->calls[] = ['model' => $model->getName(), 'input' => $input, 'options' => $options];
                $response = array_shift($this->responses) ?? throw new UnderflowException('No scripted AI response left.');
                if ($response instanceof Throwable) {
                    throw $response;
                }

                return $response;
            },
            eventDispatcher: $dispatcher,
        );
    }

    public function respondWith(string|Throwable ...$responses): void
    {
        array_push($this->responses, ...$responses);
    }

    /**
     * @return list<array{model: string, input: mixed, options: array<mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->platform->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
