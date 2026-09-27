<?php

namespace App\Ordering\Infrastructure\Foundry;

use App\Catalog\Domain\Product;
use App\Catalog\Infrastructure\Foundry\ProductFactory;
use App\Customer\Domain\Customer;
use App\Customer\Infrastructure\Foundry\CustomerFactory;
use App\Ordering\Domain\Order;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;

/**
 * Creates orders through {@see Order::place()}, so every generated order obeys the same business
 * rules as a real one: stock is reserved, prices are snapshotted and totals add up.
 *
 * @extends PersistentObjectFactory<Order>
 */
final class OrderFactory extends PersistentObjectFactory
{
    /**
     * Returns the class this factory creates.
     */
    public static function class(): string
    {
        return Order::class;
    }

    /**
     * Returns default values: a new customer buying one item of a new, in-stock product.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'customer' => CustomerFactory::new(),
            'items' => lazy(static fn (): array => [[ProductFactory::createOne(['stock' => 10, 'active' => true]), 1]]),
            'placedAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year')),
        ];
    }

    /**
     * Instantiates the order with the domain factory method instead of the private constructor.
     */
    protected function initialize(): static
    {
        return $this->instantiateWith(
            /** @param array{customer: Customer, items: list<array{Product, positive-int}>, placedAt: \DateTimeImmutable} $attributes */
            static fn (array $attributes): Order => Order::place($attributes['customer'], $attributes['items'], $attributes['placedAt']),
        );
    }
}
