<?php

namespace App\Exception;

final class ProductUnavailableException extends BusinessRuleViolation
{
    /**
     * Creates the exception for a product that has been taken out of sale.
     */
    public static function inactive(string $sku): self
    {
        return new self(sprintf('Product "%s" is not available for sale.', $sku));
    }

    /**
     * Creates the exception for a product id that does not exist.
     */
    public static function notFound(string $productId): self
    {
        return new self(sprintf('Product "%s" does not exist.', $productId));
    }
}
