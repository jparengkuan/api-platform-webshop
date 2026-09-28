<?php

namespace App\Repository;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Loads the order with SELECT … FOR UPDATE, reading its state under the lock.
     */
    public function findForUpdate(Uuid $id): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Returns the oldest unpaid orders placed before the moment first.
     */
    public function findUnpaidPlacedBefore(\DateTimeImmutable $moment, int $limit): array
    {
        /** @var list<array{id: Uuid}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.id')
            ->andWhere('o.status = :placed')
            ->andWhere('o.placedAt < :moment')
            ->setParameter('placed', OrderStatus::Placed)
            ->setParameter('moment', $moment)
            ->orderBy('o.placedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Uuid => $row['id'], $rows);
    }
}
