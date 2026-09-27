<?php

namespace App\Message;

use App\Entity\Order;

/**
 * Integration event: an order was placed. Published to RabbitMQ as JSON for other systems
 * (invoicing, fulfilment, analytics), so it carries plain values instead of entities.
 */
final readonly class OrderPlaced implements IntegrationEvent
{
    /** Routing key on the webshop.events exchange; consumers bind their queue to it. */
    public const string ROUTING_KEY = 'order.placed';

    /**
     * Creates the event.
     *
     * @param list<array{productId: string|null, sku: string, name: string, quantity: int, unitPrice: int}> $lines
     *                                                                                                             unit prices in minor units, in the order's currency
     */
    public function __construct(
        public string $orderId,
        public string $customerId,
        public \DateTimeImmutable $placedAt,
        public int $totalAmount,
        public string $currency,
        public array $lines,
    ) {
    }

    /**
     * Returns the routing key on the webshop.events exchange.
     */
    public function routingKey(): string
    {
        return self::ROUTING_KEY;
    }

    /**
     * Captures the placed order as it is at this moment.
     */
    public static function fromOrder(Order $order): self
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

        return new self(
            $order->getId()->toRfc4122(),
            $order->getCustomer()->getId()->toRfc4122(),
            $order->getPlacedAt(),
            $order->getTotal()->amount,
            $order->getTotal()->currency,
            $lines,
        );
    }
}
