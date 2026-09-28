<?php

namespace App\Exception;

use App\Enum\OrderStatus;

/**
 * The order can't be paid (anymore): it's already paid or expired, or its payment window has closed.
 */
final class OrderNotPayableException extends OrderingException
{
    /**
     * Creates the exception for an order that isn't awaiting payment.
     */
    public static function notAwaitingPayment(OrderStatus $status): self
    {
        return new self(sprintf('This order can\'t be paid: it is %s.', $status->value));
    }

    /**
     * Creates the exception for an order whose payment window has closed.
     */
    public static function paymentWindowClosed(): self
    {
        return new self('The time to pay this order has passed.');
    }
}
