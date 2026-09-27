<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CustomerAuthTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PASSWORD = 'S3cure-webshop-pass!';

    private Client $client;

    /**
     * Creates an API client and starts every test with an empty customer table.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE customer CASCADE');
    }

    /**
     * Registration returns the new customer with a normalized email and never the password.
     */
    public function testRegisterCreatesCustomerWithoutExposingPassword(): void
    {
        $response = $this->register('Jan@Example.com');

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains([
            '@type' => 'Customer',
            'email' => 'jan@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Jansen',
        ]);
        $data = $response->toArray();
        self::assertSame('/api/customers/'.$data['id'], $data['@id']);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('plainPassword', $data);
    }

    /**
     * Registration reports every invalid field, including an already used email.
     */
    public function testRegisterRejectsDuplicateEmailAndInvalidInput(): void
    {
        $this->register('jan@example.com');

        $this->client->request('POST', '/api/register', ['json' => [
            'email' => 'JAN@example.com',
            'firstName' => '',
            'lastName' => 'Jansen',
            'plainPassword' => 'short',
        ]]);

        self::assertResponseStatusCodeSame(422);
        $violations = array_column($this->client->getResponse()->toArray(false)['violations'], 'propertyPath');
        self::assertEqualsCanonicalizing(['email', 'firstName', 'plainPassword'], $violations);
    }

    /**
     * A JWT from the login endpoint gives access to the customer's own profile.
     */
    public function testLoginReturnsTokenThatGrantsAccessToMe(): void
    {
        $this->register('jan@example.com');

        $token = $this->login('jan@example.com', self::PASSWORD);

        $this->client->request('GET', '/api/me', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['email' => 'jan@example.com', 'firstName' => 'Jan']);
    }

    /**
     * Logging in with a wrong password is refused.
     */
    public function testLoginWithWrongPasswordFails(): void
    {
        $this->register('jan@example.com');

        $this->client->request('POST', '/api/login', ['json' => ['email' => 'jan@example.com', 'password' => 'wrong-password']]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * The profile endpoint is not available without a token.
     */
    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A customer can read their own account but not someone else's.
     */
    public function testCustomerCannotReadAnotherCustomer(): void
    {
        $own = $this->register('jan@example.com')->toArray()['@id'];
        $other = $this->register('piet@example.com')->toArray()['@id'];
        $token = $this->login('jan@example.com', self::PASSWORD);

        $this->client->request('GET', $own, ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $other, ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Registers a customer through the API with the shared test password.
     */
    private function register(string $email): ResponseInterface
    {
        return $this->client->request('POST', '/api/register', ['json' => [
            'email' => $email,
            'firstName' => 'Jan',
            'lastName' => 'Jansen',
            'plainPassword' => self::PASSWORD,
        ]]);
    }

    /**
     * Logs in through the API and returns the JWT.
     */
    private function login(string $email, string $password): string
    {
        $response = $this->client->request('POST', '/api/login', ['json' => ['email' => $email, 'password' => $password]]);
        self::assertResponseIsSuccessful();

        return $response->toArray()['token'];
    }
}
