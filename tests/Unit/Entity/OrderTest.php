<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Order;
use App\Entity\Product;
use App\Exception\EmptyOrderException;
use App\Exception\InsufficientStockException;
use App\Exception\MixedCurrencyException;
use App\Exception\ProductUnavailableException;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /**
     * Placing an order reserves stock and totals the line prices.
     */
    public function testPlaceReservesStockAndTotalsLines(): void
    {
        $mug = $this->product('MUG-01', 1250, stock: 10);
        $shirt = $this->product('SHIRT-01', 2499, stock: 5);
        $placedAt = new \DateTimeImmutable('2026-09-27 12:00');

        $order = Order::place(new Customer(), [[$mug, 2], [$shirt, 1]], $placedAt);

        self::assertSame($placedAt, $order->getPlacedAt());
        self::assertTrue($order->getTotal()->equals(new Money(4999, 'EUR')));
        self::assertCount(2, $order->getLines());
        self::assertSame(8, $mug->getStock());
        self::assertSame(4, $shirt->getStock());
    }

    /**
     * Later catalog changes do not alter an existing order line.
     */
    public function testLinesKeepPriceAtTimeOfOrder(): void
    {
        $mug = $this->product('MUG-01', 1250, stock: 10);

        $line = Order::place(new Customer(), [[$mug, 1]], new \DateTimeImmutable())->getLines()[0];
        $mug->setPrice(new Money(9999, 'EUR'))->setName('Renamed');

        self::assertSame(1250, $line->getUnitPrice()->amount);
        self::assertSame('Product MUG-01', $line->getProductName());
    }

    /**
     * Only the customer who placed the order is recognized as its owner.
     */
    public function testIsPlacedBy(): void
    {
        $customer = new Customer();
        $order = Order::place($customer, [[$this->product('MUG-01', 100, 1), 1]], new \DateTimeImmutable());

        self::assertTrue($order->isPlacedBy($customer));
        self::assertFalse($order->isPlacedBy(new Customer()));
        self::assertFalse($order->isPlacedBy(null));
    }

    /**
     * An order without lines cannot be placed.
     */
    public function testEmptyOrderIsRejected(): void
    {
        $this->expectException(EmptyOrderException::class);

        Order::place(new Customer(), [], new \DateTimeImmutable());
    }

    /**
     * An order for more items than in stock cannot be placed.
     */
    public function testInsufficientStockIsRejected(): void
    {
        $this->expectException(InsufficientStockException::class);

        Order::place(new Customer(), [[$this->product('MUG-01', 100, stock: 1), 2]], new \DateTimeImmutable());
    }

    /**
     * A product taken out of sale cannot be ordered.
     */
    public function testInactiveProductIsRejected(): void
    {
        $this->expectException(ProductUnavailableException::class);

        Order::place(new Customer(), [[$this->product('MUG-01', 100, 5)->setActive(false), 1]], new \DateTimeImmutable());
    }

    /**
     * Mixed currencies are rejected before any stock is reserved.
     */
    public function testMixedCurrenciesAreRejectedBeforeStockIsTouched(): void
    {
        $eur = $this->product('MUG-EUR', 100, 5);
        $usd = $this->product('MUG-USD', 100, 5, 'USD');

        try {
            Order::place(new Customer(), [[$eur, 1], [$usd, 1]], new \DateTimeImmutable());
            self::fail('Expected MixedCurrencyException.');
        } catch (MixedCurrencyException) {
            self::assertSame(5, $eur->getStock());
        }
    }

    /**
     * Creates an in-memory product for the tests.
     */
    private function product(string $sku, int $amount, int $stock, string $currency = 'EUR'): Product
    {
        return new Product()
            ->setSku($sku)
            ->setName('Product '.$sku)
            ->setPrice(new Money($amount, $currency))
            ->setStock($stock);
    }
}
