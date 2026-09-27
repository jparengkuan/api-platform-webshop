<?php

namespace App\Tests\Unit\Ordering;

use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use App\Message\OrderPlaced;
use App\Ordering\PlaceOrder;
use App\Ordering\PlaceOrderHandler;
use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class PlaceOrderHandlerTest extends TestCase
{
    private Product $mug;

    /** Whether the order transaction has ended; the stubbed entity manager sets it. */
    private bool $committed = false;

    /** @var list<array{Envelope, bool}> dispatched envelopes, with whether the order was committed by then */
    private array $dispatched = [];

    /** @var list<string> */
    private array $errors = [];

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
     * The event is published only after the transaction committed, with the order.placed routing key.
     */
    public function testPublishesOrderPlacedAfterCommit(): void
    {
        $order = $this->handler(brokerDown: false)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 2]]));

        self::assertCount(1, $this->dispatched);
        [$envelope, $committedBeforePublish] = $this->dispatched[0];
        self::assertTrue($committedBeforePublish, 'The event must not be published before the order is committed.');
        self::assertSame(OrderPlaced::ROUTING_KEY, $envelope->last(AmqpStamp::class)?->getRoutingKey());
        self::assertEquals(OrderPlaced::fromOrder($order), $envelope->getMessage());
    }

    /**
     * An unreachable broker doesn't undo or fail the order; the failure is logged.
     */
    public function testUnreachableBrokerDoesNotFailTheOrder(): void
    {
        $order = $this->handler(brokerDown: true)(new PlaceOrder(new Customer(), [[$this->mug->getId(), 2]]));

        self::assertSame(2500, $order->getTotal()->amount);
        self::assertSame(['Could not publish OrderPlaced for order {orderId}.'], $this->errors);
    }

    /**
     * Builds the handler with in-memory collaborators that record into this test case.
     */
    private function handler(bool $brokerDown): PlaceOrderHandler
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findForUpdate')->willReturn([$this->mug->getId()->toRfc4122() => $this->mug]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $work): mixed {
            $result = $work();
            $this->committed = true;

            return $result;
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []) use ($brokerDown): Envelope {
            if ($brokerDown) {
                throw new TransportException('Connection refused');
            }
            $envelope = Envelope::wrap($message, $stamps);
            $this->dispatched[] = [$envelope, $this->committed];

            return $envelope;
        });

        $logger = new class(function (string $message): void {
            $this->errors[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        };

        return new PlaceOrderHandler(
            $products,
            $this->createStub(OrderRepositoryInterface::class),
            $entityManager,
            new MockClock('2026-09-28 12:00'),
            $bus,
            $logger,
        );
    }
}
