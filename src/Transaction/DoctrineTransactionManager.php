<?php

namespace App\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Adapter: a Doctrine ORM transaction. Flushes before the commit; on failure it rolls back and closes
 * the entity manager, so half-changed entities can never be flushed afterwards.
 */
#[AsAlias(TransactionManagerInterface::class)]
final readonly class DoctrineTransactionManager implements TransactionManagerInterface
{
    /**
     * Creates the transaction manager.
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Runs the operation in a transaction.
     */
    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }
}
