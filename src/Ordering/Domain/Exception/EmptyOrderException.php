<?php

namespace App\Ordering\Domain\Exception;

final class EmptyOrderException extends OrderingException
{
    /**
     * Creates the exception with a fixed, client-safe message.
     */
    public function __construct()
    {
        parent::__construct('An order must contain at least one line.');
    }
}
