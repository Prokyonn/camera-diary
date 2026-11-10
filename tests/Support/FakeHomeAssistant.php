<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Answers Home Assistant history requests in tests (framework.http_client.mock_response_factory).
 */
final class FakeHomeAssistant
{
    /** @var list<array{entity_id: string, state: string, last_changed: string, attributes?: array<string, mixed>}> */
    private array $states = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
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
}
