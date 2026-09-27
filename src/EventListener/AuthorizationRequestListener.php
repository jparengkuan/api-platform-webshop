<?php

namespace App\EventListener;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Decides on every authorization request (/authorize) once the user is logged in.
 *
 * All clients are first-party (only administrators register them), so there is no consent screen:
 * a request is approved as soon as it uses PKCE with S256. OAuth 2.1 requires PKCE for every
 * client, but the OAuth2 server only enforces it for public clients, so this also covers
 * confidential (server-side) clients. A denied request is redirected back with "access_denied".
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AuthorizationRequestListener
{
    /**
     * Approves requests with an S256 code challenge and denies all others.
     */
    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $usesPkce = null !== $event->getCodeChallenge() && 'S256' === $event->getCodeChallengeMethod();

        $event->resolveAuthorization($usesPkce
            ? AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED
            : AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);
    }
}
