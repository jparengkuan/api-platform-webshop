<?php

namespace App\Dto;

use App\Entity\Product;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of {@see PlaceOrderInput}.
 */
final readonly class OrderLineInput
{
    /**
     * Creates the line; the serializer resolves the product IRI to the entity.
     *
     * @param Product|null $product  the product, sent as IRI, e.g. "/api/products/0199…"
     * @param int|null     $quantity number of items, 1 to 100
     */
    public function __construct(
        #[Assert\NotNull]
        public ?Product $product = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: 100)]
        public ?int $quantity = null,
    ) {
    }
}
