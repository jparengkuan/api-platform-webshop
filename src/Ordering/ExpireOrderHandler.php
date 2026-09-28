<?php

namespace App\Ordering;

use App\Repository\OrderRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use App\Transaction\TransactionManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: expire an unpaid order and put its reserved stock back.
 */
final readonly class ExpireOrderHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private OrderRepositoryInterface $orders,
        private ProductRepositoryInterface $products,
        private TransactionManagerInterface $transactions,
    ) {
    }

    /**
     * Expires the order if it is still unpaid.
     *
     * @return bool true when the order expired now
     */
    public function __invoke(Uuid $orderId): bool
    {
        return $this->transactions->transactional(function () use ($orderId): bool {
            // The order first, then its products: a payment arriving at the same moment waits for us.
            $order = $this->orders->findForUpdate($orderId);
            if (null === $order) {
                return false;
            }

            // Lock the products like placing an order does, so returned stock is never lost.
            $productIds = [];
            foreach ($order->getLines() as $line) {
                if (null !== $product = $line->getProduct()) {
                    $productIds[] = $product->getId();
                }
            }
            $this->products->findForUpdate($productIds);

            return $order->expire();
        });
    }
}
