<?php

namespace App\Payment;

use App\Entity\Order;

/**
 * Port for starting a payment with a payment provider's hosted checkout page.
 */
interface CheckoutGatewayInterface
{
    /**
     * Creates a checkout for the order's lines and total; the customer is sent to its URL to pay.
     *
     * @throws \RuntimeException when the payment provider can't be reached
     */
    public function createCheckout(Order $order): Checkout;
}
