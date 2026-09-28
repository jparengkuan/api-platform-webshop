<?php

namespace App\Ordering;

use Symfony\Component\Uid\Uuid;

/**
 * Command: expire this order if it is still unpaid.
 */
final readonly class ExpireOrder
{
    /**
     * Creates the command.
     */
    public function __construct(public Uuid $orderId)
    {
    }
}
