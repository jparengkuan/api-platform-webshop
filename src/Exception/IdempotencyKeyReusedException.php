<?php

namespace App\Exception;

/**
 * The client sent an Idempotency-Key it already used for a different order.
 */
final class IdempotencyKeyReusedException extends OrderingException
{
    /**
     * Creates the exception.
     */
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used for an order with different lines; use a new key for a new order.');
    }
}
