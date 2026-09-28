<?php

namespace App\Ordering;

use App\Entity\Order;

/**
 * Command: the customer wants to pay this order.
 */
final readonly class StartPayment
{
    /**
     * Creates the command.
     */
    public function __construct(public Order $order)
    {
    }
}
