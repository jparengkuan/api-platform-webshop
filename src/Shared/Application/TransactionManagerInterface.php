<?php

namespace App\Shared\Application;

/**
 * Port for running a use case in one database transaction, without knowing the persistence layer.
 */
interface TransactionManagerInterface
{
    /**
     * Runs the operation in a transaction and commits it; on any exception everything is rolled back
     * and the exception is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
