<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository implements ProductRepositoryInterface
{
    /**
     * Binds the repository to the Product entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Loads the products with SELECT ... FOR UPDATE, in id order to avoid deadlocks.
     */
    public function findForUpdate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Product> $products */
        $products = $this->createQueryBuilder('p')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            // A fixed lock order prevents deadlocks between orders containing the same products.
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // Products may already be in the identity map; reload them so stock is read under the lock.
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $byId = [];
        foreach ($products as $product) {
            $byId[$product->getId()->toRfc4122()] = $product;
        }

        return $byId;
    }
}
