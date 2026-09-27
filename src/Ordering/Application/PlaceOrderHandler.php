<?php

namespace App\Ordering\Application;

use App\Catalog\Domain\Exception\ProductUnavailableException;
use App\Catalog\Domain\ProductRepositoryInterface;
use App\Ordering\Application\Event\OrderPlaced;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderRepositoryInterface;
use App\Shared\Application\EventPublisherInterface;
use App\Shared\Application\TransactionManagerInterface;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: place an order. Owns the transaction boundary and announces the result; the business
 * rules live in the {@see Order} and {@see \App\Catalog\Domain\Product} entities. Depends on ports
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
        $order = $this->transactions->transactional(fn (): Order => $this->place($command));
        $this->events->publish(OrderPlaced::fromOrder($order));

        return $order;
    }

    /**
     * Locks the products, lets the domain place the order and stores it.
     *
     * @throws BusinessRuleViolation when a business rule prevents the order
     */
    private function place(PlaceOrder $command): Order
    {
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
    }
}
