<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\ExpireUnpaidOrders;
use App\Ordering\ExpireOrder;
use App\Repository\OrderRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Expires every order that is still unpaid {@see Order::EXPIRES_AFTER_MINUTES} minutes after it was
 * placed. Runs every few minutes in the Messenger worker.
 */
#[AsMessageHandler]
final readonly class ExpireUnpaidOrdersHandler
{
    /** Orders per run; the next run picks up the rest. */
    private const int BATCH_SIZE = 100;

    /**
     * Creates the handler.
     */
    public function __construct(
        private OrderRepositoryInterface $orders,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatches an {@see ExpireOrder} per overdue order. They run after this handler, each in its own
     * transaction, so one failing order doesn't keep the others from expiring.
     */
    public function __invoke(ExpireUnpaidOrders $task): void
    {
        $deadline = $this->clock->now()->modify('-'.Order::EXPIRES_AFTER_MINUTES.' minutes');

        $overdue = $this->orders->findUnpaidPlacedBefore($deadline, self::BATCH_SIZE);
        foreach ($overdue as $orderId) {
            $this->bus->dispatch(new ExpireOrder($orderId), [new DispatchAfterCurrentBusStamp()]);
        }

        if ([] !== $overdue) {
            $this->logger->info('Expiring {count} unpaid orders.', ['count' => \count($overdue)]);
        }
    }
}
