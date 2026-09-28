<?php

namespace App\Payment;

/**
 * A started payment: the page the customer pays on, valid until it expires.
 */
final readonly class Checkout
{
    /**
     * Creates the checkout.
     */
    public function __construct(
        public string $id,
        public string $url,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
