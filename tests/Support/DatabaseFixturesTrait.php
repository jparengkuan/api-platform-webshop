<?php

namespace App\Tests\Support;

use App\Catalog\Domain\Category;
use App\Catalog\Domain\Product;
use App\Catalog\Infrastructure\Foundry\ProductFactory;
use App\Customer\Domain\Customer;
use App\Customer\Infrastructure\Foundry\CustomerFactory;
use App\Shared\Domain\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Database fixtures for kernel and API tests, built with the Foundry factories.
 */
trait DatabaseFixturesTrait
{
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
}
