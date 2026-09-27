<?php

namespace App\Ordering;

use App\Entity\Order;
use App\Exception\OrderingException;
use App\Exception\ProductUnavailableException;
use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
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
    ) {
    }

    /**
     * Places the order in a single transaction and returns it.
     *
     * @throws OrderingException when a business rule prevents the order; nothing is persisted
     */
    public function __invoke(PlaceOrder $command): Order
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
}
