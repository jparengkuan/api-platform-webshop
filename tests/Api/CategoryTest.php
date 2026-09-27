<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Catalog\Domain\Category;
use App\Catalog\Infrastructure\Foundry\CategoryFactory;

final class CategoryTest extends ApiTestCase
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
     * Anyone can list the categories, sorted by name and without pagination.
     */
    public function testCategoriesArePublic(): void
    {
        CategoryFactory::createOne(['name' => 'Tassen']);
        CategoryFactory::createOne(['name' => 'Kleding']);

        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalItems' => 2, 'member' => [['name' => 'Kleding', 'slug' => 'kleding'], ['name' => 'Tassen']]]);
        self::assertMatchesResourceCollectionJsonSchema(Category::class);
    }

    /**
     * An admin creates categories; the slug is derived from the name unless given explicitly.
     */
    public function testAdminCreatesCategories(): void
    {
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Mokken & flessen']]);
        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['name' => 'Mokken & flessen', 'slug' => 'mokken-flessen']);

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Cadeaus', 'slug' => 'kado']]);
        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['slug' => 'kado']);
    }

    /**
     * Duplicate names and malformed slugs are rejected with 422.
     */
    public function testInvalidCategoryIsRejected(): void
    {
        CategoryFactory::createOne(['name' => 'Kleding']);
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Kleding']]);
        self::assertResponseStatusCodeSame(422);
        $violations = array_column($this->client->getResponse()->toArray(false)['violations'], 'propertyPath');
        self::assertEqualsCanonicalizing(['name', 'slug'], $violations);

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Audio', 'slug' => 'Audio & Video']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'slug']]]);
    }

    /**
     * Regular customers cannot manage categories.
     */
    public function testCustomersCannotManageCategories(): void
    {
        $category = CategoryFactory::createOne(['name' => 'Kleding']);
        $this->createCustomer('jan@example.com');
        $token = $this->login('jan@example.com');

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Tassen']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', $this->categoryIri($category), ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A category can only be deleted once no product belongs to it anymore.
     */
    public function testCategoryWithProductsCannotBeDeleted(): void
    {
        $clothing = CategoryFactory::createOne(['name' => 'Kleding']);
        $empty = CategoryFactory::createOne(['name' => 'Leeg']);
        $this->createProduct('HOO-01', 4995, 10, category: $clothing);
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');

        $this->client->request('DELETE', $this->categoryIri($clothing), ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['detail' => 'Category "Kleding" still has products. Move them to another category first.']);

        $this->client->request('DELETE', $this->categoryIri($empty), ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(204);
    }
}
