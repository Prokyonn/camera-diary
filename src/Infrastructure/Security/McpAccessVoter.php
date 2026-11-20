<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Access to the MCP server: open without an MCP_TOKEN (the demo, a server only reachable in a private network),
 * otherwise only with that bearer token (McpTokenHandler).
 *
 * @extends Voter<string, null>
 */
final class McpAccessVoter extends Voter
{
    public const string ATTRIBUTE = 'MCP_ACCESS';

    public function __construct(
        #[Autowire(env: 'MCP_TOKEN')]
        #[SensitiveParameter]
        private readonly string $token,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return '' === $this->token || \in_array(McpTokenHandler::ROLE, $token->getRoleNames(), true);
    }
}
