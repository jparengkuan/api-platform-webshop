<?php

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use App\Factory\CustomerFactory;
use App\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures (built with the Foundry factories) and auth helpers shared by the webshop API tests.
 *
 * @property Client $client
 */
trait WebshopTestTrait
{
    /**
     * Empties all webshop tables so every test starts from a known state.
     */
    private function resetDatabase(): void
    {
        $this->entityManager()->getConnection()->executeStatement('TRUNCATE customer, product, customer_order, order_line CASCADE');
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
     */
    private function createProduct(string $sku, int $amount, int $stock, string $currency = 'EUR', bool $active = true): Product
    {
        return ProductFactory::createOne([
            'sku' => $sku,
            'price' => new Money($amount, $currency),
            'stock' => $stock,
            'active' => $active,
        ]);
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
     * Logs in through the API and returns the JWT.
     */
    private function login(string $email): string
    {
        $response = $this->client->request('POST', '/api/login', ['json' => ['email' => $email, 'password' => CustomerFactory::PASSWORD]]);
        self::assertResponseIsSuccessful();

        return $response->toArray()['token'];
    }

    /**
     * Returns the API IRI of a product, as clients send it in an order.
     */
    private function iri(Product $product): string
    {
        return '/api/products/'.$product->getId()->toRfc4122();
    }
}
