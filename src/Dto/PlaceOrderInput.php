<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/orders.
 */
final readonly class PlaceOrderInput
{
    /**
     * Creates the input; the serializer fills it from the JSON request body.
     *
     * @param list<OrderLineInput> $lines
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 50)]
        #[Assert\Valid]
        public array $lines = [],
    ) {
    }
}
