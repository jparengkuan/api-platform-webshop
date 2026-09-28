<?php

namespace App\Payment;

use Symfony\Component\Uid\Uuid;

/**
 * A payment provider reports that an order has been paid.
 */
final readonly class PaymentReceived
{
    /**
     * Creates the notification.
     *
     * @param string $paymentReference the provider's id of the payment, e.g. a Stripe PaymentIntent
     */
    public function __construct(
        public Uuid $orderId,
        public string $paymentReference,
    ) {
    }
}
