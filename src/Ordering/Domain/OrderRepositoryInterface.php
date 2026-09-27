<?php

namespace App\Ordering\Domain;

interface OrderRepositoryInterface
{
    /** Registers a new order; it is written when the surrounding unit of work commits. */
    public function add(Order $order): void;
}
