<?php

namespace App\Tests\Support;

use App\Entity\Order;
use App\Payment\Checkout;
use App\Payment\CheckoutGatewayInterface;

/**
 * Stands in for Stripe in tests: returns a checkout without calling the payment provider, and
 * remembers for which orders one was created.
 *
 * The list is static because the test client reboots the kernel (and so the container) between requests.
 */
final class FakeCheckoutGateway implements CheckoutGatewayInterface
{
    /** @var list<string> ids of the orders a checkout was created for */
    private static array $orders = [];

    /**
     * Returns a fake checkout for the order.
     */
    public function createCheckout(Order $order): Checkout
    {
        self::$orders[] = $order->getId()->toRfc4122();

        return new Checkout(
            'cs_test_'.$order->getId()->toBase58(),
            'https://checkout.stripe.test/c/pay/cs_test_'.$order->getId()->toBase58(),
            new \DateTimeImmutable('+30 minutes'),
        );
    }

    /**
     * Returns the ids of the orders a checkout was created for since the last {@see self::reset()}.
     *
     * @return list<string>
     */
    public static function orders(): array
    {
        return self::$orders;
    }

    /**
     * Forgets the recorded checkouts.
     */
    public static function reset(): void
    {
        self::$orders = [];
    }
}
