<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Order;
use Symfony\Component\Uid\Uuid;

interface OrderRepositoryInterface
{
    /** Registers a new order; it is written when the surrounding unit of work commits. */
    public function add(Order $order): void;

    /**
     * Loads the order with a write lock, so concurrent payment and expiry can't both change it.
     * Must be called inside a transaction.
     */
    public function findForUpdate(Uuid $id): ?Order;

    /**
     * Returns the customer's order that was placed with this Idempotency-Key, if any.
     */
    public function findByIdempotencyKey(Customer $customer, string $idempotencyKey): ?Order;

    /**
     * Returns the ids of orders that were placed before the given moment and are still unpaid.
     *
     * @return list<Uuid>
     */
    public function findUnpaidPlacedBefore(\DateTimeImmutable $moment, int $limit): array;
}
