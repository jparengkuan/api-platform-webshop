<?php

namespace App\Ordering\Domain\Exception;

final class MixedCurrencyException extends OrderingException
{
    /**
     * Creates the exception with a fixed, client-safe message.
     */
    public function __construct()
    {
        parent::__construct('All products in an order must be priced in the same currency.');
    }
}
