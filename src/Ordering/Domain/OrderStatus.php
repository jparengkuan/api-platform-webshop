<?php

namespace App\Ordering\Domain;

enum OrderStatus: string
{
    case Placed = 'placed';
}
