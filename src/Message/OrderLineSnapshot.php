<?php

namespace App\Message;

use App\Entity\Order;

/**
 * The lines of an order as plain values, the form the order events carry them in.
 */
final class OrderLineSnapshot
{
    /**
     * Returns the order's lines; unit prices in minor units, in the order's currency.
     *
     * @return list<array{productId: string|null, sku: string, name: string, quantity: int, unitPrice: int}>
     */
    public static function listFrom(Order $order): array
    {
        $lines = [];
        foreach ($order->getLines() as $line) {
            $lines[] = [
                'productId' => $line->getProduct()?->getId()->toRfc4122(),
                'sku' => $line->getSku(),
                'name' => $line->getProductName(),
                'quantity' => $line->getQuantity(),
                'unitPrice' => $line->getUnitPrice()->amount,
            ];
        }

        return $lines;
    }
}
