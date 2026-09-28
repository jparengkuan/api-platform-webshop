<?php

namespace App\Ordering;

use App\Exception\OrderNotPayableException;
use App\Message\OrderPaid;
use App\Messenger\EventPublisherInterface;
use App\Payment\PaymentReceived;
use App\Repository\OrderRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Use case: the payment provider reports a payment. Marks the order paid and records {@see OrderPaid}
 * in the same transaction (command bus, doctrine_transaction middleware). Safe to run more than
 * once for the same payment.
 */
#[AsMessageHandler]
final readonly class ConfirmPaymentHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private OrderRepositoryInterface $orders,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Records the payment; unknown orders and payments for expired orders are logged, not thrown,
     * because the payment provider can't do anything about them.
     */
    public function __invoke(PaymentReceived $payment): void
    {
        // Locked: the expiry task can't expire this order while the payment is recorded.
        $order = $this->orders->findForUpdate($payment->orderId);
        if (null === $order) {
            $this->logger->error('Payment {reference} is for unknown order {orderId}.', [
                'reference' => $payment->paymentReference,
                'orderId' => $payment->orderId->toRfc4122(),
            ]);

            return;
        }

        try {
            $paidNow = $order->markPaid($payment->paymentReference, $this->clock->now());
        } catch (OrderNotPayableException $exception) {
            // Paid after it expired: the stock may be gone, so the payment has to be refunded.
            $this->logger->critical('Order {orderId} was paid after it could no longer be paid; refund payment {reference}.', [
                'orderId' => $payment->orderId->toRfc4122(),
                'reference' => $payment->paymentReference,
                'exception' => $exception,
            ]);

            return;
        }

        if ($paidNow) {
            $this->events->publish(OrderPaid::fromOrder($order));
        }
    }
}
