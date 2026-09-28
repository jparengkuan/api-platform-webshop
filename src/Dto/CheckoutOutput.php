<?php

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Response of POST /api/orders/{id}/payment: where to send the customer to pay.
 */
final readonly class CheckoutOutput
{
    /**
     * Creates the output.
     */
    public function __construct(
        #[Groups(['checkout:read'])]
        public string $checkoutUrl,
        #[Groups(['checkout:read'])]
        public string $checkoutId,
        #[Groups(['checkout:read'])]
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
