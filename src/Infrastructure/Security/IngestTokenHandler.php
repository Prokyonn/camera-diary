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
 * Accepts the bearer token INGEST_TOKEN for the ingest hook; with an empty INGEST_TOKEN the hook stays closed.
 */
final readonly class IngestTokenHandler implements AccessTokenHandlerInterface
{
    public const string ROLE = 'ROLE_INGEST';

    public function __construct(
        #[Autowire(env: 'INGEST_TOKEN')]
        #[SensitiveParameter]
        private string $token,
    ) {
    }

    public function getUserBadgeFrom(#[SensitiveParameter] string $accessToken): UserBadge
    {
        if ('' === $this->token || !hash_equals($this->token, $accessToken)) {
            throw new BadCredentialsException('Invalid ingest token.');
        }

        return new UserBadge('ingest', static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, [self::ROLE]));
    }
}
