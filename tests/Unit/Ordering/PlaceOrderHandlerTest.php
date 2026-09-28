<?php

namespace App\Tests\Unit\Ordering;

use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use App\Exception\InsufficientStockException;
use App\Message\OrderPlaced;
use App\Ordering\PlaceOrder;
use App\Ordering\PlaceOrderHandler;
use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use App\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PlaceOrderHandlerTest extends TestCase
{
    private Product $mug;

    /**
     * Creates a product that can be ordered.
     */
    protected function setUp(): void
    {
        $this->mug = new Product()
            ->setSku('MUG-01')
            ->setName('Mug')
            ->setPrice(new Money(1250, 'EUR'))
            ->setStock(10);
    }

    /**
     * A placed order is stored and OrderPlaced is recorded once, describing it.
     */
    public function testRecordsOrderPlaced(): void
    {
        $events = new RecordingEventPublisher();
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::once())->method('add');

        $order = $this->handler($orders, $events)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 2]]));

        self::assertEquals([OrderPlaced::fromOrder($order)], $events->published);
        self::assertSame(8, $this->mug->getStock());
    }

    /**
     * An order that breaks a business rule is neither stored nor announced (the command bus then
     * rolls the transaction back).
     */
    public function testRejectedOrderIsNotRecorded(): void
    {
        $events = new RecordingEventPublisher();
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::never())->method('add');

        try {
            $this->handler($orders, $events)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 11]]));
            self::fail('Ordering more than the stock must be rejected.');
        } catch (InsufficientStockException) {
        }

        self::assertSame([], $events->published);
    }

    /**
     * Builds the handler with in-memory adapters for its ports.
     */
    private function handler(OrderRepositoryInterface $orders, RecordingEventPublisher $events): PlaceOrderHandler
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findForUpdate')->willReturn([$this->mug->getId()->toRfc4122() => $this->mug]);

        return new PlaceOrderHandler($products, $orders, $events, new MockClock('2026-09-28 12:00'));
    }
}
