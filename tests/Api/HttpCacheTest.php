<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Catalog\Infrastructure\Foundry\CategoryFactory;
use App\Tests\Support\RecordingPurger;

/**
 * The HTTP cache contract Varnish relies on: which responses may be cached, how they are tagged,
 * and which resources are invalidated when data changes.
 */
final class HttpCacheTest extends ApiTestCase
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
     * Catalog responses may be cached by Varnish for an hour and by browsers for a minute.
     */
    public function testCatalogIsPubliclyCacheable(): void
    {
        $product = $this->createProduct('MUG-01', 1250, 10);

        foreach (['/api/products', $this->iri($product), '/api/categories'] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            $cacheControl = $this->client->getResponse()->getHeaders()['cache-control'][0];
            self::assertStringContainsString('public', $cacheControl, $url);
            self::assertStringContainsString('s-maxage=3600', $cacheControl, $url);
            self::assertStringContainsString('max-age=60', $cacheControl, $url);
            self::assertResponseHasHeader('etag');
        }
    }

    /**
     * Responses are tagged with the IRIs they contain, so Varnish can ban exactly those.
     */
    public function testResponsesAreTaggedWithTheirResources(): void
    {
        $product = $this->createProduct('MUG-01', 1250, 10);

        $this->client->request('GET', '/api/products');

        $tags = explode(',', $this->client->getResponse()->getHeaders()['cache-tags'][0]);
        self::assertContains($this->iri($product), $tags);
        self::assertContains($this->categoryIri($product->getCategory()), $tags);
        self::assertContains('/api/products', $tags);
    }

    /**
     * Personal data must never end up in a shared cache.
     */
    public function testPersonalDataIsNotPubliclyCacheable(): void
    {
        $this->createCustomer('jan@example.com');
        $token = $this->login('jan@example.com');

        foreach (['/api/me', '/api/orders'] as $url) {
            $this->client->request('GET', $url, ['auth_bearer' => $token]);

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('public', $this->client->getResponse()->getHeaders()['cache-control'][0], $url);
        }
    }

    /**
     * Changing a product bans every cached response that contains it.
     */
    public function testChangingAProductInvalidatesIt(): void
    {
        $product = $this->createProduct('MUG-01', 1250, 10);
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');
        RecordingPurger::reset();

        $this->client->request('PATCH', $this->iri($product), [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['price' => ['amount' => 999, 'currency' => 'EUR']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertContains($this->iri($product), RecordingPurger::purged());
    }

    /**
     * Placing an order changes stock, so the ordered products are invalidated too.
     */
    public function testPlacingAnOrderInvalidatesTheOrderedProducts(): void
    {
        $product = $this->createProduct('MUG-01', 1250, 10);
        $this->createCustomer('jan@example.com');
        $token = $this->login('jan@example.com');
        RecordingPurger::reset();

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => [
            ['product' => $this->iri($product), 'quantity' => 1],
        ]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertContains($this->iri($product), RecordingPurger::purged());
    }

    /**
     * A new category invalidates the cached category list.
     */
    public function testAddingACategoryInvalidatesTheList(): void
    {
        CategoryFactory::createOne(['name' => 'Kleding']);
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $token = $this->login('admin@example.com');
        RecordingPurger::reset();

        $this->client->request('POST', '/api/categories', ['auth_bearer' => $token, 'json' => ['name' => 'Tassen']]);

        self::assertResponseStatusCodeSame(201);
        self::assertContains('/api/categories', RecordingPurger::purged());
    }
}
