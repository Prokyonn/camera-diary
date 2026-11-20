<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Access to the MCP server: with an MCP_TOKEN only with that bearer token (McpTokenHandler). Without one it is closed,
 * like the ingest hook, unless MCP_OPEN says it may be open (the demo, a server only reachable in a private network).
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
        #[Autowire(env: 'bool:MCP_OPEN')]
        private readonly bool $openWithoutToken,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ('' === $this->token) {
            return $this->openWithoutToken;
        }

        return \in_array(McpTokenHandler::ROLE, $token->getRoleNames(), true);
    }
}
