<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Product;
use App\Factory\CategoryFactory;

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
     * Anyone can browse the catalog without logging in, including each product's category.
     */
    public function testCatalogIsPublic(): void
    {
        $category = CategoryFactory::createOne(['name' => 'Mokken & flessen']);
        $this->createProduct('MUG-01', 1250, 10, category: $category);

        $this->client->request('GET', '/api/products');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalItems' => 1, 'member' => [[
            'sku' => 'MUG-01',
            'price' => ['amount' => 1250, 'currency' => 'EUR'],
            'category' => ['@id' => $this->categoryIri($category), 'name' => 'Mokken & flessen', 'slug' => 'mokken-flessen'],
        ]]]);
        self::assertMatchesResourceCollectionJsonSchema(Product::class);
    }

    /**
     * The catalog can be filtered by category, both by IRI and by slug.
     */
    public function testCatalogCanBeFilteredByCategory(): void
    {
        $clothing = CategoryFactory::createOne(['name' => 'Kleding']);
        $bags = CategoryFactory::createOne(['name' => 'Tassen']);
        $this->createProduct('HOO-01', 4995, 10, category: $clothing);
        $this->createProduct('TSH-01', 1995, 10, category: $clothing);
        $this->createProduct('RUG-01', 5995, 10, category: $bags);

        $this->client->request('GET', '/api/products', ['query' => ['category' => $this->categoryIri($bags)]]);
        self::assertJsonContains(['totalItems' => 1, 'member' => [['sku' => 'RUG-01']]]);

        $this->client->request('GET', '/api/products?category.slug=kleding');
        self::assertJsonContains(['totalItems' => 2]);

        $this->client->request('GET', '/api/products?category.slug=onbekend');
        self::assertJsonContains(['totalItems' => 0]);
    }

    /**
     * An admin can create a product and change its price, stock and category.
     */
    public function testAdminManagesProducts(): void
    {
        $mugs = CategoryFactory::createOne(['name' => 'Mokken & flessen']);
        $gifts = CategoryFactory::createOne(['name' => 'Cadeaus']);
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $iri = $this->client->request('POST', '/api/products', ['auth_bearer' => $token, 'json' => [
            'sku' => 'MUG-01',
            'name' => 'Mug',
            'category' => $this->categoryIri($mugs),
            'price' => ['amount' => 1250, 'currency' => 'EUR'],
            'stock' => 10,
        ]])->toArray()['@id'];
        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['category' => ['slug' => 'mokken-flessen']]);

        $this->client->request('PATCH', $iri, [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['price' => ['amount' => 999, 'currency' => 'EUR'], 'stock' => 3, 'category' => $this->categoryIri($gifts)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['price' => ['amount' => 999], 'stock' => 3, 'category' => ['slug' => 'cadeaus']]);
    }

    /**
     * Negative prices, a missing category and invalid fields are rejected with 422.
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
        self::assertEqualsCanonicalizing(['sku', 'name', 'category', 'price.currency', 'stock'], $violations);
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
