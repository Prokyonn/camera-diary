<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Mcp;

use App\Infrastructure\Security\McpAccessVoter;
use App\Infrastructure\Security\McpTokenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(McpTokenHandler::class)]
#[CoversClass(McpAccessVoter::class)]
final class McpAccessTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['MCP_TOKEN'], $_ENV['MCP_TOKEN']);
        parent::tearDown();
    }

    public function testIsOpenWithoutAnMcpToken(): void
    {
        $client = self::createClient();

        $this->initialize($client);

        self::assertResponseIsSuccessful();
    }

    public function testNeedsTheBearerTokenOnceOneIsSet(): void
    {
        $_SERVER['MCP_TOKEN'] = $_ENV['MCP_TOKEN'] = 'secret-token';
        $client = self::createClient();

        $this->initialize($client);
        self::assertResponseStatusCodeSame(401, 'No token.');

        $this->initialize($client, 'guessed');
        self::assertResponseStatusCodeSame(401, 'A wrong token.');

        $this->initialize($client, 'secret-token');
        self::assertResponseIsSuccessful();
    }

    private function initialize(KernelBrowser $client, ?string $token = null): void
    {
        $client->request('POST', '/mcp', server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => null === $token ? null : 'Bearer '.$token,
        ]), content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], \JSON_THROW_ON_ERROR));
    }
}
