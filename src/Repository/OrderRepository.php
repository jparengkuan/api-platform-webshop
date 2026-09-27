<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Order>
 */
#[AsAlias(OrderRepositoryInterface::class)]
class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    /**
     * Binds the repository to the Order entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Schedules the order for insertion; the caller flushes.
     */
    public function add(Order $order): void
    {
        $this->getEntityManager()->persist($order);
    }
}
