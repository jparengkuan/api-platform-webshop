<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Product;

final class ProductTest extends ApiTestCase
{
    use WebshopTestTrait;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    /**
     * Creates an API client and starts every test with an empty database.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
    }

    /**
     * Anyone can browse the catalog without logging in.
     */
    public function testCatalogIsPublic(): void
    {
        $this->createProduct('MUG-01', 1250, 10);

        $this->client->request('GET', '/api/products');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalItems' => 1, 'member' => [['sku' => 'MUG-01', 'price' => ['amount' => 1250, 'currency' => 'EUR']]]]);
        self::assertMatchesResourceCollectionJsonSchema(Product::class);
    }

    /**
     * An admin can create a product and change its price and stock.
     */
    public function testAdminManagesProducts(): void
    {
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $iri = $this->client->request('POST', '/api/products', ['auth_bearer' => $token, 'json' => [
            'sku' => 'MUG-01',
            'name' => 'Mug',
            'price' => ['amount' => 1250, 'currency' => 'EUR'],
            'stock' => 10,
        ]])->toArray()['@id'];
        self::assertResponseStatusCodeSame(201);

        $this->client->request('PATCH', $iri, [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['price' => ['amount' => 999, 'currency' => 'EUR'], 'stock' => 3],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['price' => ['amount' => 999], 'stock' => 3]);
    }

    /**
     * Negative prices and invalid fields are rejected with 422.
     */
    public function testInvalidProductIsRejected(): void
    {
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $this->client->request('POST', '/api/products', ['auth_bearer' => $token, 'json' => [
            'sku' => 'MUG-01',
            'name' => 'Mug',
            'price' => ['amount' => -1, 'currency' => 'EUR'],
        ]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/products', ['auth_bearer' => $token, 'json' => [
            'sku' => 'mug 01',
            'name' => '',
            'price' => ['amount' => 100, 'currency' => 'XXX'],
            'stock' => -1,
        ]]);
        self::assertResponseStatusCodeSame(422);
        $violations = array_column($this->client->getResponse()->toArray(false)['violations'], 'propertyPath');
        self::assertEqualsCanonicalizing(['sku', 'name', 'price.currency', 'stock'], $violations);
    }

    /**
     * Regular customers cannot create products.
     */
    public function testCustomersCannotManageProducts(): void
    {
        $this->createCustomer('jan@example.com');

        $this->client->request('POST', '/api/products', ['auth_bearer' => $this->login('jan@example.com'), 'json' => [
            'sku' => 'MUG-01',
            'name' => 'Mug',
            'price' => ['amount' => 1250, 'currency' => 'EUR'],
        ]]);

        self::assertResponseStatusCodeSame(403);
    }
}
