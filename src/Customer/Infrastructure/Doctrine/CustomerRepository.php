<?php

namespace App\Customer\Infrastructure\Doctrine;

use App\Customer\Domain\Customer;
use App\Customer\Domain\CustomerRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Customer>
 */
#[AsAlias(CustomerRepositoryInterface::class)]
class CustomerRepository extends ServiceEntityRepository implements CustomerRepositoryInterface, PasswordUpgraderInterface
{
    /**
     * Binds the repository to the Customer entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Returns the customer with this id, or null when there is none.
     */
    public function findById(Uuid $id): ?Customer
    {
        return $this->find($id);
    }

    /** Rehashes the password automatically when the hashing algorithm changes. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Customer) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
