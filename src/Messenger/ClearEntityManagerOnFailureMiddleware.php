<?php

namespace App\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Wraps the doctrine_transaction middleware: when a command fails, its transaction is rolled back,
 * but the entities it changed are still managed. Detach them, so for example stock deducted in
 * memory can never be flushed by the next command in the same request or worker run.
 */
final readonly class ClearEntityManagerOnFailureMiddleware implements MiddlewareInterface
{
    /**
     * Creates the middleware.
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Passes the message on; clears the entity manager when handling it fails.
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $this->entityManager->clear();

            throw $exception;
        }
    }
}
