<?php

namespace App\Ordering;

use App\Entity\Order;
use App\Exception\OrderNotPayableException;
use App\Payment\Checkout;
use App\Payment\CheckoutGatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Use case: start paying an order. The amount comes from the order itself, never from the client.
 */
final readonly class StartPaymentHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private CheckoutGatewayInterface $checkouts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Returns a checkout the customer can pay the order with.
     *
     * @throws OrderNotPayableException when the order isn't awaiting payment or its payment window has closed
     * @throws \RuntimeException        when the payment provider can't be reached
     */
    public function __invoke(Order $order): Checkout
    {
        $order->assertPayable($this->clock->now());

        return $this->checkouts->createCheckout($order);
    }
}
