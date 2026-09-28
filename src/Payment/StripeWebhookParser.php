<?php

namespace App\Payment;

use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies a Stripe webhook call and translates it into what it means for an order. Needs no network:
 * the signature is checked with the endpoint's signing secret.
 */
final readonly class StripeWebhookParser
{
    /** Events after which the Checkout session's payment has succeeded. */
    private const array PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    /**
     * Creates the parser.
     */
    public function __construct(
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private string $webhookSecret,
    ) {
    }

    /**
     * Returns the payment the call reports, or null for events that don't concern a paid order.
     *
     * @throws \UnexpectedValueException when the signature or the payload is invalid
     */
    public function parse(string $payload, string $signatureHeader): ?PaymentReceived
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
        } catch (SignatureVerificationException $exception) {
            throw new \UnexpectedValueException('Invalid Stripe signature: '.$exception->getMessage(), 0, $exception);
        }

        $session = $event->data->object;
        if (!\in_array($event->type, self::PAID_EVENTS, true) || !$session instanceof Session || 'paid' !== $session->payment_status) {
            return null;
        }

        $orderId = (string) $session->client_reference_id;
        if (!Uuid::isValid($orderId)) {
            throw new \UnexpectedValueException(sprintf('Checkout session %s has no valid order id.', $session->id));
        }

        $paymentIntent = $session->payment_intent;

        return new PaymentReceived(
            Uuid::fromString($orderId),
            \is_string($paymentIntent) ? $paymentIntent : (string) ($paymentIntent->id ?? $session->id),
        );
    }
}
