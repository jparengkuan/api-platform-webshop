<?php

namespace App\Story;

use App\Entity\Customer;
use App\Entity\Product;
use App\Factory\CustomerFactory;
use App\Factory\OrderFactory;
use App\Factory\ProductFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;

/**
 * A complete development webshop: fixed accounts to log in with, a catalog and a year of orders.
 *
 * Load it with `bin/console foundry:load-fixtures`; set FOUNDRY_FAKER_SEED for a reproducible data set.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public const string ADMIN_EMAIL = 'admin@example.com';
    public const string CUSTOMER_EMAIL = 'klant@example.com';

    private const int CUSTOMERS = 50;
    private const int PRODUCTS = 200;
    private const int ORDERS = 500;

    /**
     * Builds the data set.
     */
    public function build(): void
    {
        $this->addState('admin', CustomerFactory::new()->admin()->create([
            'email' => self::ADMIN_EMAIL,
            'firstName' => 'Anna',
            'lastName' => 'de Beheerder',
        ]));

        $customers = [
            CustomerFactory::createOne(['email' => self::CUSTOMER_EMAIL, 'firstName' => 'Jan', 'lastName' => 'Jansen']),
            ...CustomerFactory::createMany(self::CUSTOMERS),
        ];
        $products = ProductFactory::createMany(self::PRODUCTS);

        for ($i = 0; $i < self::ORDERS; ++$i) {
            $this->placeRandomOrder(faker()->randomElement($customers), $products);
        }
    }

    /**
     * Places an order of 1 to 5 different products, skipping products that can't be sold.
     *
     * @param list<Product> $products
     */
    private function placeRandomOrder(Customer $customer, array $products): void
    {
        $items = [];
        foreach (faker()->randomElements($products, faker()->numberBetween(1, 5)) as $product) {
            $quantity = faker()->randomElement([1, 1, 1, 2, 2, 3]);
            if ($product->isActive() && $product->getStock() >= $quantity) {
                $items[] = [$product, $quantity];
            }
        }

        if ([] === $items) {
            return;
        }

        // Orders are spread over the last year, but never before the customer registered.
        $since = max($customer->getCreatedAt(), new \DateTimeImmutable('-1 year'));

        OrderFactory::createOne([
            'customer' => $customer,
            'items' => $items,
            'placedAt' => \DateTimeImmutable::createFromMutable(faker()->dateTimeBetween(\DateTime::createFromImmutable($since))),
        ]);
    }
}
