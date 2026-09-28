<?php

namespace App\Tests\Integration;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Factory\OrderFactory;
use App\Message\ExpireUnpaidOrders;
use App\Tests\Support\DatabaseFixturesTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scheduled task that expires unpaid orders and puts their stock back.
 */
final class ExpireUnpaidOrdersTest extends KernelTestCase
{
    use DatabaseFixturesTrait;

    /**
     * Starts every test with an empty database.
     */
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    /**
     * Only orders that are unpaid for too long expire; their stock is available again.
     */
    public function testExpiresOverdueUnpaidOrders(): void
    {
        $mug = $this->createProduct('MUG-01', 1250, 10);
        $overdue = $this->order($mug, 3, '-'.(Order::EXPIRES_AFTER_MINUTES + 1).' minutes', paid: false);
        $recent = $this->order($mug, 2, '-10 minutes', paid: false);
        $paid = $this->order($mug, 1, '-2 days', paid: true);
        self::assertSame(4, $this->reloadProduct($mug)->getStock());

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ExpireUnpaidOrders());

        self::assertSame(OrderStatus::Expired, $this->statusOf($overdue));
        self::assertSame(OrderStatus::Placed, $this->statusOf($recent));
        self::assertSame(OrderStatus::Paid, $this->statusOf($paid));
        self::assertSame(7, $this->reloadProduct($mug)->getStock(), 'The 3 items of the expired order are back in stock.');
    }

    /**
     * Running the task again changes nothing and doesn't return the stock twice.
     */
    public function testRunningTwiceReleasesStockOnce(): void
    {
        $mug = $this->createProduct('MUG-01', 1250, 10);
        $this->order($mug, 3, '-2 hours', paid: false);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $bus->dispatch(new ExpireUnpaidOrders());
        $bus->dispatch(new ExpireUnpaidOrders());

        self::assertSame(10, $this->reloadProduct($mug)->getStock());
    }

    /**
     * Creates an order for the product.
     */
    private function order(\App\Entity\Product $product, int $quantity, string $placed, bool $paid): Order
    {
        return OrderFactory::createOne([
            'items' => [[$product, $quantity]],
            'placedAt' => new \DateTimeImmutable($placed),
            'paid' => $paid,
        ]);
    }

    /**
     * Reads the order's status straight from the database.
     */
    private function statusOf(Order $order): OrderStatus
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return ($entityManager->find(Order::class, $order->getId()) ?? throw new \LogicException('The order is gone.'))->getStatus();
    }
}
