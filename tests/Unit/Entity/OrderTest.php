<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Exception\EmptyOrderException;
use App\Exception\InsufficientStockException;
use App\Exception\MixedCurrencyException;
use App\Exception\OrderNotPayableException;
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
     * A placed order can be paid within the payment window; paying is recorded once.
     */
    public function testMarkPaidIsIdempotent(): void
    {
        $order = Order::place(new Customer(), [[$this->product('MUG-01', 1250, 10), 1]], new \DateTimeImmutable('2026-09-28 12:00'));
        $paidAt = new \DateTimeImmutable('2026-09-28 12:05');

        self::assertTrue($order->markPaid('pi_1', $paidAt));
        self::assertFalse($order->markPaid('pi_1', new \DateTimeImmutable('2026-09-28 12:06')), 'A repeated notification changes nothing.');

        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertSame($paidAt, $order->getPaidAt());
        self::assertSame('pi_1', $order->getPaymentReference());
    }

    /**
     * A payment can only be started within the payment window of a placed order.
     */
    public function testPaymentWindow(): void
    {
        $placedAt = new \DateTimeImmutable('2026-09-28 12:00');
        $order = Order::place(new Customer(), [[$this->product('MUG-01', 1250, 10), 1]], $placedAt);

        $order->assertPayable($placedAt->modify('+29 minutes'));

        $this->expectExceptionObject(OrderNotPayableException::paymentWindowClosed());
        $order->assertPayable($placedAt->modify('+'.Order::PAYMENT_WINDOW_MINUTES.' minutes'));
    }

    /**
     * Expiring puts the reserved stock back, once; an expired order can't be paid anymore.
     */
    public function testExpireReleasesStock(): void
    {
        $mug = $this->product('MUG-01', 1250, 10);
        $order = Order::place(new Customer(), [[$mug, 3]], new \DateTimeImmutable());

        self::assertTrue($order->expire());
        self::assertFalse($order->expire());

        self::assertSame(OrderStatus::Expired, $order->getStatus());
        self::assertSame(10, $mug->getStock());
        $this->expectException(OrderNotPayableException::class);
        $order->markPaid('pi_late', new \DateTimeImmutable());
    }

    /**
     * A paid order never expires.
     */
    public function testPaidOrderDoesNotExpire(): void
    {
        $mug = $this->product('MUG-01', 1250, 10);
        $order = Order::place(new Customer(), [[$mug, 3]], new \DateTimeImmutable());
        $order->markPaid('pi_1', new \DateTimeImmutable());

        self::assertFalse($order->expire());
        self::assertSame(7, $mug->getStock());
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
