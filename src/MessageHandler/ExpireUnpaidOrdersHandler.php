<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\ExpireUnpaidOrders;
use App\Ordering\ExpireOrderHandler;
use App\Repository\OrderRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Expires every order that is still unpaid {@see Order::EXPIRES_AFTER_MINUTES} minutes after it was
 * placed, each in its own transaction. Runs every few minutes in the Messenger worker.
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
        private ExpireOrderHandler $expireOrder,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Expires the overdue orders.
     */
    public function __invoke(ExpireUnpaidOrders $task): void
    {
        $deadline = $this->clock->now()->modify('-'.Order::EXPIRES_AFTER_MINUTES.' minutes');

        $expired = 0;
        foreach ($this->orders->findUnpaidPlacedBefore($deadline, self::BATCH_SIZE) as $orderId) {
            $expired += ($this->expireOrder)($orderId) ? 1 : 0;
        }

        if ($expired > 0) {
            $this->logger->info('Expired {count} unpaid orders.', ['count' => $expired]);
        }
    }
}
