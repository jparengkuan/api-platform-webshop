<?php

namespace App\Ordering\Application;

use App\Customer\Domain\Customer;
use Symfony\Component\Uid\Uuid;

/**
 * Command: the customer wants to buy these quantities. Free of HTTP/API concerns, so the same
 * use case can be triggered from a controller, a console command or a message handler.
 */
final readonly class PlaceOrder
{
    /** @var array<string, positive-int> quantity per RFC 4122 product id, duplicates merged */
    public array $quantities;

    /**
     * Creates the command and merges lines that refer to the same product.
     *
     * @param iterable<array{Uuid, positive-int}> $lines product id and quantity pairs
     */
    public function __construct(
        public Customer $customer,
        iterable $lines,
    ) {
        $quantities = [];
        foreach ($lines as [$productId, $quantity]) {
            $key = $productId->toRfc4122();
            $quantities[$key] = ($quantities[$key] ?? 0) + $quantity;
        }

        $this->quantities = $quantities;
    }
}
