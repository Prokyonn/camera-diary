<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\HomeAssistant;

use CameraEvents\HomeAssistant\Application\History\HomeHistoryInterface;
use CameraEvents\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraEvents\HomeAssistant\Application\History\StateChange;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use UnexpectedValueException;

/**
 * Reads state changes from the Home Assistant history API.
 */
final readonly class HomeAssistantHistory implements HomeHistoryInterface
{
    public function __construct(
        #[Target('home_assistant.client')]
        private HttpClientInterface $client,
    ) {
    }

    public function fetch(array $entityIds, DateTimeImmutable $from, DateTimeImmutable $to, bool $withAttributes): array
    {
        if ([] === $entityIds) {
            return [];
        }

        $query = [
            'end_time' => $to->format(DateTimeInterface::ATOM),
            'filter_entity_id' => implode(',', $entityIds),
            'significant_changes_only' => '0',
        ];
        if (!$withAttributes) {
            $query['minimal_response'] = '1';
            $query['no_attributes'] = '1';
        }

        try {
            $payload = $this->client->request('GET', '/api/history/period/'.rawurlencode($from->format(DateTimeInterface::ATOM)), [
                'query' => $query,
            ])->toArray();
            $parsed = $this->parse($payload);
        } catch (ExceptionInterface|UnexpectedValueException $exception) {
            throw new HomeHistoryUnavailableException('Home Assistant history could not be read: '.$exception->getMessage(), 0, $exception);
        }

        $changes = [];
        foreach ($parsed as $change) {
            if ($change->changedAt > $from && $change->changedAt <= $to) {
                $changes[] = $change;
            }
        }

        usort($changes, static fn (StateChange $a, StateChange $b): int => $a->changedAt <=> $b->changedAt);

        return $changes;
    }

    private function parseTime(string $value): DateTimeImmutable
    {
        $time = new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));

        return $time->setTime((int) $time->format('H'), (int) $time->format('i'), (int) $time->format('s'));
    }

    /**
     * @param array<mixed> $payload one list of states per entity; with minimal_response only the first state names the entity
     *
     * @return list<StateChange>
     */
    private function parse(array $payload): array
    {
        $changes = [];
        foreach ($payload as $states) {
            if (!\is_array($states) || [] === $states) {
                continue;
            }

            $entityId = null;
            foreach ($states as $state) {
                if (!\is_array($state)) {
                    throw new UnexpectedValueException('Home Assistant returned a state that is not an object.');
                }

                $entityId = \is_string($state['entity_id'] ?? null) ? $state['entity_id'] : $entityId;
                if (null === $entityId || !\is_string($state['state'] ?? null) || !\is_string($state['last_changed'] ?? null)) {
                    throw new UnexpectedValueException('Home Assistant returned a state without entity, state or time.');
                }

                $attributes = \is_array($state['attributes'] ?? null) ? $state['attributes'] : [];

                /** @var array<string, mixed> $attributes */
                $changes[] = new StateChange($entityId, $state['state'], $this->parseTime($state['last_changed']), $attributes);
            }
        }

        return $changes;
    }
}
