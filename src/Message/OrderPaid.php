<?php

namespace App\Message;

use App\Entity\Order;

/**
 * Integration event: an order has been paid. Published to RabbitMQ as JSON; the invoice service
 * turns it into an invoice PDF.
 */
final readonly class OrderPaid implements IntegrationEvent
{
    /** Routing key on the webshop.events exchange; consumers bind their queue to it. */
    public const string ROUTING_KEY = 'order.paid';

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
        public \DateTimeImmutable $paidAt,
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
     * Captures the paid order.
     *
     * @throws \LogicException when the order isn't paid
     */
    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->getId()->toRfc4122(),
            $order->getCustomer()->getId()->toRfc4122(),
            $order->getPlacedAt(),
            $order->getPaidAt() ?? throw new \LogicException('Only a paid order can be announced as paid.'),
            $order->getTotal()->amount,
            $order->getTotal()->currency,
            OrderLineSnapshot::listFrom($order),
        );
    }
}
