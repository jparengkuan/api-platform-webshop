<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Factory\OAuthClientFactory;

/**
 * Logging in is only possible through the OAuth2 Authorization Code grant with PKCE, by a registered client.
 */
final class OAuthTest extends ApiTestCase
{
    use WebshopTestTrait;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    /**
     * Creates an API client, starts with an empty database and registers the test application.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
        $this->ensureOAuthClient();
        $this->createCustomer('jan@example.com');
    }

    /**
     * The whole flow returns a bearer access token (1 hour) and a refresh token that grant API access.
     */
    public function testAuthorizationCodeFlowIssuesTokens(): void
    {
        $tokens = $this->obtainTokens('jan@example.com');

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertNotEmpty($tokens['refresh_token']);

        $this->client->request('GET', '/api/me', ['auth_bearer' => $tokens['access_token']]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['email' => 'jan@example.com']);
    }

    /**
     * Users who aren't logged in are sent to the login page, which is a real, CSRF-protected form.
     */
    public function testAuthorizeSendsAnonymousUsersToTheLoginPage(): void
    {
        $this->client->request('GET', $this->authorizeUrl(['code_challenge' => $this->codeChallenge('verifier')]));
        self::assertResponseRedirects('http://localhost/login');

        $page = $this->client->request('GET', '/login')->getContent();
        self::assertStringContainsString('name="email"', $page);
        self::assertStringContainsString('name="password"', $page);
        self::assertStringContainsString('name="_csrf_token"', $page);
    }

    /**
     * A wrong password keeps the user on the login page, without reaching the client application.
     */
    public function testWrongPasswordShowsAnError(): void
    {
        $this->client->request('GET', $this->authorizeUrl(['code_challenge' => $this->codeChallenge('verifier')]));
        $this->submitLoginForm('jan@example.com', 'wrong-password');

        self::assertResponseRedirects('http://localhost/login');
        self::assertStringContainsString('Onjuist e-mailadres of wachtwoord.', $this->client->request('GET', '/login')->getContent());
    }

    /**
     * Without PKCE the request goes back to the client with "access_denied" (our listener); the weak
     * "plain" method is already rejected by the OAuth2 server itself.
     */
    public function testPkceWithS256IsRequired(): void
    {
        $this->logInOnTheLoginPage();

        $withoutPkce = $this->client->request('GET', $this->authorizeUrl(['code_challenge_method' => null]));
        $location = $withoutPkce->getHeaders(false)['location'][0];
        self::assertStringStartsWith(self::OAUTH_REDIRECT_URI, $location);
        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringNotContainsString('code=', $location);

        $this->client->request('GET', $this->authorizeUrl(['code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'plain']));
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['error' => 'invalid_request']);
    }

    /**
     * A redirect URI that isn't registered for the client is refused, so codes can't leak elsewhere.
     */
    public function testUnregisteredRedirectUriIsRefused(): void
    {
        $this->logInOnTheLoginPage();

        $this->client->request('GET', $this->authorizeUrl([
            'code_challenge' => $this->codeChallenge('verifier'),
            'redirect_uri' => 'https://evil.example.com/callback',
        ]));

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['error' => 'invalid_client']);
    }

    /**
     * The token endpoint only accepts the client with its correct secret.
     */
    public function testTokenEndpointRequiresTheClientSecret(): void
    {
        $this->client->request('POST', '/token', $this->form([
            'grant_type' => 'authorization_code',
            'client_id' => self::OAUTH_CLIENT,
            'client_secret' => 'wrong-secret',
            'redirect_uri' => self::OAUTH_REDIRECT_URI,
            'code' => 'irrelevant',
            'code_verifier' => 'irrelevant',
        ]));

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['error' => 'invalid_client']);
    }

    /**
     * A refresh token gets a new access token without logging in again.
     */
    public function testRefreshTokenIssuesANewAccessToken(): void
    {
        $tokens = $this->obtainTokens('jan@example.com');

        $refreshed = $this->client->request('POST', '/token', $this->form([
            'grant_type' => 'refresh_token',
            'client_id' => self::OAUTH_CLIENT,
            'client_secret' => OAuthClientFactory::SECRET,
            'refresh_token' => $tokens['refresh_token'],
        ]))->toArray();

        self::assertNotSame($tokens['access_token'], $refreshed['access_token']);
        $this->client->request('GET', '/api/me', ['auth_bearer' => $refreshed['access_token']]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Only the Authorization Code and Refresh Token grants exist: no username/password at /token.
     */
    public function testPasswordGrantIsDisabled(): void
    {
        $this->client->request('POST', '/token', $this->form([
            'grant_type' => 'password',
            'client_id' => self::OAUTH_CLIENT,
            'client_secret' => OAuthClientFactory::SECRET,
            'username' => 'jan@example.com',
            'password' => 'password',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['error' => 'unsupported_grant_type']);
    }

    /**
     * Starts a browser session in which Jan is logged in, as after the first steps of the flow.
     */
    private function logInOnTheLoginPage(): void
    {
        $this->client->request('GET', $this->authorizeUrl(['code_challenge' => $this->codeChallenge('verifier')]));
        $this->submitLoginForm('jan@example.com', 'password');
        self::assertResponseRedirects();
    }

    /**
     * The old JSON login endpoint no longer exists, and made-up tokens are rejected.
     */
    public function testNoOtherWayToGetAccess(): void
    {
        $this->client->request('POST', '/api/login', ['json' => ['email' => 'jan@example.com', 'password' => 'password']]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/me', ['auth_bearer' => 'not-a-real-token']);
        self::assertResponseStatusCodeSame(401);
    }
}
