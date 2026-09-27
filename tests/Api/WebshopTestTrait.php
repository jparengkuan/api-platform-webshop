<?php

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use App\Factory\CustomerFactory;
use App\Factory\OAuthClientFactory;
use App\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures (built with the Foundry factories) and auth helpers shared by the webshop API tests.
 *
 * @property Client $client
 */
trait WebshopTestTrait
{
    /** The OAuth2 client the tests log in with, as a server-side application would. */
    private const string OAUTH_CLIENT = 'test-app';
    private const string OAUTH_REDIRECT_URI = 'https://app.example.com/callback';

    /**
     * Empties all webshop and OAuth2 tables so every test starts from a known state.
     */
    private function resetDatabase(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'TRUNCATE customer, category, product, customer_order, order_line, '
            .'oauth2_client, oauth2_authorization_code, oauth2_access_token, oauth2_refresh_token CASCADE',
        );
    }

    /**
     * Returns the entity manager of the currently booted kernel.
     */
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Creates a customer; everything a test does not pin down gets a realistic random value.
     *
     * @param list<string> $roles
     */
    private function createCustomer(string $email, array $roles = []): Customer
    {
        return CustomerFactory::createOne(['email' => $email, 'roles' => $roles]);
    }

    /**
     * Creates a product with the given SKU, price, stock and sale status pinned down.
     *
     * Without a category the product lands in a realistic one for its random product type.
     */
    private function createProduct(string $sku, int $amount, int $stock, string $currency = 'EUR', bool $active = true, ?Category $category = null): Product
    {
        return ProductFactory::createOne(array_filter([
            'sku' => $sku,
            'price' => new Money($amount, $currency),
            'stock' => $stock,
            'active' => $active,
            'category' => $category,
        ], static fn (mixed $value): bool => null !== $value));
    }

    /**
     * Reads the product straight from the database, bypassing the identity map.
     */
    private function reloadProduct(Product $product): Product
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->find(Product::class, $product->getId());
    }

    /**
     * Logs in through the OAuth2 Authorization Code flow and returns the access token.
     */
    private function login(string $email, string $password = CustomerFactory::PASSWORD): string
    {
        return $this->obtainTokens($email, $password)['access_token'];
    }

    /**
     * Runs the whole Authorization Code flow with PKCE, like a server-side application:
     * /authorize → login page → /authorize → callback with a code → /token.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function obtainTokens(string $email, string $password = CustomerFactory::PASSWORD): array
    {
        $this->ensureOAuthClient();
        $verifier = bin2hex(random_bytes(32));
        $authorizeUrl = $this->authorizeUrl(['code_challenge' => $this->codeChallenge($verifier)]);

        // A fresh browser session per login, so logging in as someone else really does.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $authorizeUrl);
        self::assertResponseRedirects('http://localhost/login');

        $this->submitLoginForm($email, $password);
        self::assertResponseRedirects();

        $callback = $this->client->request('GET', $authorizeUrl)->getHeaders(false)['location'][0];
        parse_str((string) parse_url($callback, \PHP_URL_QUERY), $params);
        self::assertArrayHasKey('code', $params, 'The authorization server did not return a code: '.$callback);

        return $this->client->request('POST', '/token', $this->form([
            'grant_type' => 'authorization_code',
            'client_id' => self::OAUTH_CLIENT,
            'client_secret' => OAuthClientFactory::SECRET,
            'redirect_uri' => self::OAUTH_REDIRECT_URI,
            'code' => $params['code'],
            'code_verifier' => $verifier,
        ]))->toArray();
    }

    /**
     * Fills in and submits the login page, including its CSRF token.
     */
    private function submitLoginForm(string $email, string $password): void
    {
        $page = $this->client->request('GET', '/login')->getContent();
        self::assertMatchesRegularExpression('/name="_csrf_token" value="([^"]+)"/', $page);
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $csrf);

        $this->client->request('POST', '/login', $this->form([
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $csrf[1],
        ]));
    }

    /**
     * Returns request options that send the fields as an HTML form, like a browser or OAuth2 client does.
     *
     * The test client passes "extra.parameters" to the kernel as POST parameters; a raw
     * form-encoded body would not be parsed in a test request.
     *
     * @param array<string, string> $fields
     *
     * @return array{headers: array<string, string>, extra: array{parameters: array<string, string>}}
     */
    private function form(array $fields): array
    {
        return [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'extra' => ['parameters' => $fields],
        ];
    }

    /**
     * Returns the /authorize URL of the test client; parameters can be overridden or removed (null).
     *
     * @param array<string, string|null> $overrides
     */
    private function authorizeUrl(array $overrides = []): string
    {
        $params = array_filter([
            'response_type' => 'code',
            'client_id' => self::OAUTH_CLIENT,
            'redirect_uri' => self::OAUTH_REDIRECT_URI,
            'scope' => 'api',
            'state' => 'test-state',
            'code_challenge_method' => 'S256',
            ...$overrides,
        ], static fn (?string $value): bool => null !== $value);

        return '/authorize?'.http_build_query($params);
    }

    /**
     * Returns the S256 PKCE challenge for a code verifier.
     */
    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Registers the test application as OAuth2 client, once per test.
     */
    private function ensureOAuthClient(): void
    {
        if (null === OAuthClientFactory::repository()->findOneBy(['identifier' => self::OAUTH_CLIENT])) {
            OAuthClientFactory::createOne(['identifier' => self::OAUTH_CLIENT, 'redirectUris' => [self::OAUTH_REDIRECT_URI]]);
        }
    }

    /**
     * Returns the API IRI of a product, as clients send it in an order.
     */
    private function iri(Product $product): string
    {
        return '/api/products/'.$product->getId()->toRfc4122();
    }

    /**
     * Returns the API IRI of a category, as clients send it for a product.
     */
    private function categoryIri(Category $category): string
    {
        return '/api/categories/'.$category->getId()->toRfc4122();
    }
}
