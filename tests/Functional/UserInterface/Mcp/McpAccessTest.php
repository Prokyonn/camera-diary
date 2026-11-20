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
    private const array VARIABLES = ['MCP_TOKEN', 'MCP_ALLOWED_HOSTS'];

    /** @var array<string, mixed> the values from .env, restored after each test */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = $_SERVER[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
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

    public function testAnswersOnlyToTheAllowedHostNames(): void
    {
        $client = self::createClient();
        $this->initialize($client, host: 'cameras.example');
        self::assertResponseStatusCodeSame(403, 'Only localhost by default.');

        self::ensureKernelShutdown();
        $_SERVER['MCP_ALLOWED_HOSTS'] = $_ENV['MCP_ALLOWED_HOSTS'] = 'localhost,cameras.example';
        $client = self::createClient();
        $this->initialize($client, host: 'cameras.example');
        self::assertResponseIsSuccessful();
    }

    private function initialize(KernelBrowser $client, ?string $token = null, string $host = 'localhost'): void
    {
        $server = ['HTTP_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $client->request('POST', '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], \JSON_THROW_ON_ERROR));
    }
}
