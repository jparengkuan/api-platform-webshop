<?php

namespace App\Repository;

use App\Entity\Customer;
use Symfony\Component\Uid\Uuid;

/**
 * Port for looking up customers, e.g. from another bounded context.
 */
interface CustomerRepositoryInterface
{
    /**
     * Returns the customer with this id, or null when there is none.
     */
    public function findById(Uuid $id): ?Customer;
}
