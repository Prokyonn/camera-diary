<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Functional\Infrastructure\HomeAssistant;

use App\Tests\Factory\CameraEventFactory;
use CameraEvents\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraEvents\HomeAssistant\Infrastructure\HomeAssistant\HomeAssistantHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HomeAssistantHistory::class)]
final class HomeAssistantHistoryTest extends TestCase
{
    public function testReadsMinimalResponsesInTimeOrder(): void
    {
        $response = new JsonMockResponse([
            [
                ['entity_id' => 'binary_sensor.garden_person', 'state' => 'off', 'last_changed' => '2026-11-07T13:00:00+00:00'],
                ['state' => 'on', 'last_changed' => '2026-11-07T13:55:02.731+00:00'],
            ],
            [
                ['entity_id' => 'binary_sensor.terrace_person', 'state' => 'on', 'last_changed' => '2026-11-07T13:54:00+01:00'],
            ],
        ]);
        $client = new MockHttpClient($response, 'http://home-assistant.test');

        $changes = new HomeAssistantHistory($client)->fetch(
            ['binary_sensor.garden_person', 'binary_sensor.terrace_person'],
            CameraEventFactory::utc('2026-11-07 12:00:00'),
            CameraEventFactory::utc('2026-11-07 14:00:00'),
            false,
        );

        self::assertStringStartsWith('http://home-assistant.test/api/history/period/2026-11-07T12%3A00%3A00%2B00%3A00?', $response->getRequestUrl());
        self::assertStringContainsString('minimal_response=1', $response->getRequestUrl());
        self::assertCount(3, $changes);
        self::assertSame('binary_sensor.terrace_person', $changes[0]->entityId, 'Times with an offset are converted to UTC.');
        self::assertSame('2026-11-07 12:54:00', $changes[0]->changedAt->format('Y-m-d H:i:s'));
        self::assertSame('binary_sensor.garden_person', $changes[2]->entityId, 'Later states inherit the entity of the first.');
        self::assertSame('2026-11-07 13:55:02.000000', $changes[2]->changedAt->format('Y-m-d H:i:s.u'), 'Times are truncated to seconds.');
    }

    public function testSkipsTheInitialStateAtTheWindowStart(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([[
            ['entity_id' => 'binary_sensor.luna', 'state' => 'on', 'last_changed' => '2026-11-07T12:00:00+00:00', 'attributes' => ['since' => '2026-11-07T11:58:00+00:00']],
        ]]), 'http://home-assistant.test');

        self::assertSame([], new HomeAssistantHistory($client)->fetch(['binary_sensor.luna'], CameraEventFactory::utc('2026-11-07 12:00:00'), CameraEventFactory::utc('2026-11-07 13:00:00'), true));
    }

    public function testReportsAnUnreachableHomeAssistant(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]), 'http://home-assistant.test');

        $this->expectException(HomeHistoryUnavailableException::class);

        new HomeAssistantHistory($client)->fetch(['binary_sensor.luna'], CameraEventFactory::utc('2026-11-07 12:00:00'), CameraEventFactory::utc('2026-11-07 13:00:00'), true);
    }
}
