<?php

namespace App\Tests\Unit\Ordering\Application;

use App\Catalog\Domain\Exception\InsufficientStockException;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductRepositoryInterface;
use App\Customer\Domain\Customer;
use App\Ordering\Application\Event\OrderPlaced;
use App\Ordering\Application\PlaceOrder;
use App\Ordering\Application\PlaceOrderHandler;
use App\Ordering\Domain\OrderRepositoryInterface;
use App\Shared\Application\TransactionManagerInterface;
use App\Shared\Domain\Money;
use App\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PlaceOrderHandlerTest extends TestCase
{
    private Product $mug;

    /** Whether the transaction has committed; set by the in-memory transaction manager. */
    private bool $committed = false;

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
     * OrderPlaced is published once, only after the transaction committed, and describes the order.
     */
    public function testPublishesOrderPlacedAfterCommit(): void
    {
        $committedWhenPublished = null;
        $events = new RecordingEventPublisher(function () use (&$committedWhenPublished): void {
            $committedWhenPublished = $this->committed;
        });

        $order = $this->handler($events)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 2]]));

        self::assertEquals([OrderPlaced::fromOrder($order)], $events->published);
        self::assertTrue($committedWhenPublished, 'The event must not be published before the order is committed.');
    }

    /**
     * An order that breaks a business rule is rolled back and never announced.
     */
    public function testRejectedOrderIsNotPublished(): void
    {
        $events = new RecordingEventPublisher();

        try {
            $this->handler($events)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 11]]));
            self::fail('Ordering more than the stock must be rejected.');
        } catch (InsufficientStockException) {
        }

        self::assertFalse($this->committed);
        self::assertSame([], $events->published);
    }

    /**
     * Builds the handler with in-memory adapters for its ports.
     */
    private function handler(RecordingEventPublisher $events): PlaceOrderHandler
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findForUpdate')->willReturn([$this->mug->getId()->toRfc4122() => $this->mug]);

        $transactions = $this->createStub(TransactionManagerInterface::class);
        $transactions->method('transactional')->willReturnCallback(function (callable $operation): mixed {
            $result = $operation();
            $this->committed = true;

            return $result;
        });

        return new PlaceOrderHandler(
            $products,
            $this->createStub(OrderRepositoryInterface::class),
            $transactions,
            $events,
            new MockClock('2026-09-28 12:00'),
        );
    }
}
