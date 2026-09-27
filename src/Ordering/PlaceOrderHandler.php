<?php

namespace App\Ordering;

use App\Entity\Order;
use App\Exception\OrderingException;
use App\Exception\ProductUnavailableException;
use App\Message\OrderPlaced;
use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: place an order. Owns the transaction boundary; the business rules live in the
 * {@see Order} and {@see \App\Entity\Product} entities.
 */
final readonly class PlaceOrderHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private ProductRepositoryInterface $products,
        private OrderRepositoryInterface $orders,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Places the order, announces it with an {@see OrderPlaced} event and returns it.
     *
     * @throws OrderingException when a business rule prevents the order; nothing is persisted
     */
    public function __invoke(PlaceOrder $command): Order
    {
        $order = $this->place($command);
        $this->publish(OrderPlaced::fromOrder($order));

        return $order;
    }

    /**
     * Places the order in a single transaction.
     *
     * @throws OrderingException when a business rule prevents the order; nothing is persisted
     */
    private function place(PlaceOrder $command): Order
    {
        // On failure the transaction is rolled back and the entity manager closed,
        // so partially reserved stock can never be flushed afterwards.
        return $this->entityManager->wrapInTransaction(function () use ($command): Order {
            $ids = array_map(Uuid::fromString(...), array_keys($command->quantities));
            $products = $this->products->findForUpdate($ids);

            $items = [];
            foreach ($command->quantities as $productId => $quantity) {
                $product = $products[$productId] ?? throw ProductUnavailableException::notFound($productId);
                $items[] = [$product, $quantity];
            }

            $order = Order::place($command->customer, $items, $this->clock->now());
            $this->orders->add($order);

            return $order;
        });
    }

    /**
     * Publishes the event once the order is committed, so nobody hears of an order that was
     * rolled back. The order stands even when the broker is unreachable: the failure is logged
     * and the event is lost, the trade-off of publishing directly instead of through an outbox.
     */
    private function publish(OrderPlaced $event): void
    {
        try {
            $this->bus->dispatch($event, [new AmqpStamp(OrderPlaced::ROUTING_KEY)]);
        } catch (MessengerException $exception) {
            $this->logger->error('Could not publish OrderPlaced for order {orderId}.', [
                'orderId' => $event->orderId,
                'exception' => $exception,
            ]);
        }
    }
}
