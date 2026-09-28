<?php

namespace App\Payment;

use App\Entity\Order;
use Psr\Clock\ClockInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adapter: a Stripe Checkout session (Stripe's hosted payment page) for the order.
 *
 * Only immediate payment methods (cards, including Apple Pay and Google Pay, and iDEAL): the stock
 * is reserved until the order expires, so a payment that settles days later can't be waited for.
 */
#[AsAlias(CheckoutGatewayInterface::class)]
final readonly class StripeCheckoutGateway implements CheckoutGatewayInterface
{
    /** How long Stripe keeps a checkout open; 30 minutes is Stripe's minimum. */
    private const int CHECKOUT_MINUTES = 30;

    /**
     * Creates the gateway.
     *
     * @param string $frontendUrl where Stripe sends the customer back to, e.g. https://www.example.com
     */
    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private string $secretKey,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Creates the Stripe Checkout session.
     *
     * @throws \RuntimeException when Stripe can't be reached or rejects the request
     */
    public function createCheckout(Order $order): Checkout
    {
        $orderId = $order->getId()->toRfc4122();
        $frontendUrl = rtrim($this->frontendUrl, '/');

        $lineItems = [];
        foreach ($order->getLines() as $line) {
            $lineItems[] = [
                'quantity' => $line->getQuantity(),
                'price_data' => [
                    'currency' => strtolower($line->getUnitPrice()->currency),
                    'unit_amount' => $line->getUnitPrice()->amount,
                    'product_data' => ['name' => $line->getProductName()],
                ],
            ];
        }

        try {
            $session = new StripeClient($this->secretKey)->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card', 'ideal'],
                'line_items' => $lineItems,
                'customer_email' => $order->getCustomer()->getEmail(),
                'client_reference_id' => $orderId,
                'metadata' => ['order_id' => $orderId],
                'payment_intent_data' => ['metadata' => ['order_id' => $orderId]],
                'expires_at' => $this->clock->now()->getTimestamp() + self::CHECKOUT_MINUTES * 60,
                'success_url' => $frontendUrl.'/checkout/success?order='.$orderId.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl.'/checkout/cancelled?order='.$orderId,
            ]);
        } catch (ApiErrorException $exception) {
            throw new \RuntimeException('Stripe could not create the checkout: '.$exception->getMessage(), 0, $exception);
        }

        return new Checkout(
            $session->id,
            (string) $session->url,
            new \DateTimeImmutable('@'.$session->expires_at),
        );
    }
}
