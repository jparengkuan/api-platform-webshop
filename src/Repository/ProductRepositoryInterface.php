<?php

namespace App\Repository;

use App\Entity\Product;
use Symfony\Component\Uid\Uuid;

interface ProductRepositoryInterface
{
    /**
     * Loads products with a pessimistic write lock, so concurrent orders cannot oversell stock.
     * Must be called inside a transaction; the locks are released on commit or rollback.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, Product> keyed by the RFC 4122 product id; unknown ids are absent
     */
    public function findForUpdate(array $ids): array;
}
