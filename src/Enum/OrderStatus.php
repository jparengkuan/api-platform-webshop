<?php

namespace App\Enum;

/**
 * Lifecycle of an order: placed (stock reserved, awaiting payment) → paid, or → expired when it
 * isn't paid in time (the stock is released again).
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case Paid = 'paid';
    case Expired = 'expired';
}
