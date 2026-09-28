<?php

namespace App\Ordering;

use App\Entity\Order;
use App\Exception\BusinessRuleViolation;
use App\Exception\IdempotencyKeyReusedException;
use App\Exception\ProductUnavailableException;
use App\Message\OrderPlaced;
use App\Messenger\EventPublisherInterface;
use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use App\Transaction\TransactionManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: place an order. Owns the transaction boundary and announces the result; the business
 * rules live in the {@see Order} and {@see \App\Entity\Product} entities. Depends on ports
 * only, so it knows nothing about Doctrine, Messenger or RabbitMQ.
 */
final readonly class PlaceOrderHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private ProductRepositoryInterface $products,
        private OrderRepositoryInterface $orders,
        private TransactionManagerInterface $transactions,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Places the order in one transaction and, once it is committed, publishes {@see OrderPlaced}:
     * nobody hears of an order that was rolled back.
     *
     * @throws BusinessRuleViolation when a business rule prevents the order; nothing is persisted
     */
    public function __invoke(PlaceOrder $command): Order
    {
        [$order, $placed] = $this->transactions->transactional(fn (): array => $this->place($command));
        if ($placed) {
            $this->events->publish(OrderPlaced::fromOrder($order));
        }

        return $order;
    }

    /**
     * Locks the products, lets the domain place the order and stores it. A repeated request (same
     * Idempotency-Key) gets the order it already placed instead.
     *
     * @return array{Order, bool} the order, and whether it was placed now
     *
     * @throws BusinessRuleViolation when a business rule prevents the order
     */
    private function place(PlaceOrder $command): array
    {
        $ids = array_map(Uuid::fromString(...), array_keys($command->quantities));
        $products = $this->products->findForUpdate($ids);

        // After the product locks: a request for the same cart that is still running holds them, so
        // by now its order is committed and found here. The unique index catches anything else.
        if (null !== $command->idempotencyKey) {
            $existing = $this->orders->findByIdempotencyKey($command->customer, $command->idempotencyKey);
            if (null !== $existing) {
                return $existing->hasQuantities($command->quantities) ? [$existing, false] : throw new IdempotencyKeyReusedException();
            }
        }

        $items = [];
        foreach ($command->quantities as $productId => $quantity) {
            $product = $products[$productId] ?? throw ProductUnavailableException::notFound($productId);
            $items[] = [$product, $quantity];
        }

        $order = Order::place($command->customer, $items, $this->clock->now(), $command->idempotencyKey);
        $this->orders->add($order);

        return [$order, true];
    }
}
