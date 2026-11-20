<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Accepts the bearer token MCP_TOKEN for the MCP server. Whether one is needed at all decides McpAccessVoter.
 */
final readonly class McpTokenHandler implements AccessTokenHandlerInterface
{
    public const string ROLE = 'ROLE_MCP';

    public function __construct(
        #[Autowire(env: 'MCP_TOKEN')]
        #[SensitiveParameter]
        private string $token,
    ) {
    }

    public function getUserBadgeFrom(#[SensitiveParameter] string $accessToken): UserBadge
    {
        if ('' === $this->token || !hash_equals($this->token, $accessToken)) {
            throw new BadCredentialsException('Invalid MCP token.');
        }

        return new UserBadge('mcp', static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, [self::ROLE]));
    }
}
