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
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: place an order and announce it. Runs on the command bus, in one transaction (the
 * doctrine_transaction middleware); the business rules live in the {@see Order} and
 * {@see \App\Entity\Product} entities. Depends on ports only.
 */
#[AsMessageHandler]
final readonly class PlaceOrderHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private ProductRepositoryInterface $products,
        private OrderRepositoryInterface $orders,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Locks the products, lets the domain place the order, stores it and records {@see OrderPlaced}
     * in the same transaction: nobody hears of an order that was rolled back. A repeated request
     * (same Idempotency-Key) gets the order it already placed instead.
     *
     * @throws BusinessRuleViolation when a business rule prevents the order; nothing is persisted
     */
    public function __invoke(PlaceOrder $command): Order
    {
        $ids = array_map(Uuid::fromString(...), array_keys($command->quantities));
        $products = $this->products->findForUpdate($ids);

        // After the product locks: a request for the same cart that is still running holds them, so
        // by now its order is committed and found here. The unique index catches anything else.
        if (null !== $command->idempotencyKey) {
            $existing = $this->orders->findByIdempotencyKey($command->customer, $command->idempotencyKey);
            if (null !== $existing) {
                return $existing->hasQuantities($command->quantities) ? $existing : throw new IdempotencyKeyReusedException();
            }
        }

        $items = [];
        foreach ($command->quantities as $productId => $quantity) {
            $product = $products[$productId] ?? throw ProductUnavailableException::notFound($productId);
            $items[] = [$product, $quantity];
        }

        $order = Order::place($command->customer, $items, $this->clock->now(), $command->idempotencyKey);
        $this->orders->add($order);
        $this->events->publish(OrderPlaced::fromOrder($order));

        return $order;
    }
}
