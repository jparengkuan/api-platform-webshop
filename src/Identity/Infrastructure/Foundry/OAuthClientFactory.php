<?php

namespace App\Identity\Infrastructure\Foundry;

use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Creates confidential OAuth2 clients for the Authorization Code grant (with refresh tokens),
 * like "league:oauth2-server:create-client" does in production. The secret is hashed on the way in.
 *
 * @extends PersistentObjectFactory<Client>
 */
final class OAuthClientFactory extends PersistentObjectFactory
{
    /** Identifier of the demo client in the dev and staging fixtures. */
    public const string DEMO_IDENTIFIER = 'webshop-demo';

    /** Plain-text secret of every generated client; development and tests only. */
    public const string SECRET = 'demo-secret';

    /** Where the demo client expects users back, e.g. a frontend running locally. */
    public const string DEMO_REDIRECT_URI = 'http://localhost:3000/callback';

    /**
     * Creates the factory with the OAuth2 server's own secret hasher.
     */
    public function __construct(
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $secretHasher,
    ) {
        parent::__construct();
    }

    /**
     * Returns the class this factory creates.
     */
    public static function class(): string
    {
        return Client::class;
    }

    /**
     * Returns default values; any of them can be overridden per call.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => 'Webshop demo',
            'identifier' => self::faker()->unique()->slug(2),
            'secret' => self::SECRET,
            'redirectUris' => [self::DEMO_REDIRECT_URI],
        ];
    }

    /**
     * Builds the client through its constructor and variadic setters, which Foundry can't call itself.
     */
    protected function initialize(): static
    {
        return $this->instantiateWith(
            /** @param array{name: string, identifier: string, secret: string, redirectUris: list<string>} $attributes */
            function (array $attributes): Client {
                $client = new Client($attributes['name'], $attributes['identifier'], $this->secretHasher->hash($attributes['secret']));
                $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $attributes['redirectUris']));
                $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
                $client->setScopes(new Scope('api'));

                return $client;
            },
        );
    }
}
