<?php

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\BusinessRuleViolation;

final class InsufficientStockException extends BusinessRuleViolation
{
    /**
     * Creates the exception for a product that has fewer items in stock than requested.
     */
    public function __construct(string $sku, int $requested, int $available)
    {
        parent::__construct(sprintf('Insufficient stock for "%s": %d requested, %d available.', $sku, $requested, $available));
    }
}
