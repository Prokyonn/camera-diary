<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Answers Home Assistant history, media and notify requests in tests (framework.http_client.mock_response_factory).
 */
final class FakeHomeAssistant
{
    /** @var list<array{entity_id: string, state: string, last_changed: string, attributes?: array<string, mixed>}> */
    private array $states = [];

    /** @var array<string, string> path under /media/local => content */
    private array $media = [];

    /** @var list<array{service: string, payload: mixed}> */
    private array $notifications = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        if (str_starts_with($path, '/media/local/')) {
            $file = substr($path, \strlen('/media/local/'));

            return isset($this->media[$file]) ? new MockResponse($this->media[$file]) : new MockResponse('Not found', ['http_code' => 404]);
        }

        if ('POST' === $method && str_starts_with($path, '/api/services/notify/')) {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            $this->notifications[] = ['service' => substr($path, \strlen('/api/services/notify/')), 'payload' => json_decode($body, true)];

            return new JsonMockResponse([]);
        }

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $filter = \is_string($query['filter_entity_id'] ?? null) ? explode(',', $query['filter_entity_id']) : [];

        $byEntity = [];
        foreach ($this->states as $state) {
            if (\in_array($state['entity_id'], $filter, true)) {
                $byEntity[$state['entity_id']][] = $state;
            }
        }

        return new JsonMockResponse(array_values($byEntity));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function addState(string $entityId, string $state, string $lastChanged, array $attributes = []): void
    {
        $this->states[] = ['entity_id' => $entityId, 'state' => $state, 'last_changed' => $lastChanged, 'attributes' => $attributes];
    }

    public function addMedia(string $path, string $content): void
    {
        $this->media[$path] = $content;
    }

    /**
     * @return list<array{service: string, payload: mixed}>
     */
    public function notifications(): array
    {
        return $this->notifications;
    }
}
