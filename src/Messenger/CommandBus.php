<?php

namespace App\Messenger;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs a command synchronously on the message bus (in a transaction, see the middleware in
 * config/packages/messenger.yaml) and returns its handler's result. Throws the handler's own
 * exception instead of Messenger's wrapper, so API Platform maps e.g. a BusinessRuleViolation to
 * 422 as usual.
 */
final class CommandBus
{
    use HandleTrait;

    /**
     * Creates the command bus.
     */
    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * Handles the command and returns the result of its handler.
     *
     * @throws \Throwable whatever the handler threw; the transaction has been rolled back
     */
    public function dispatch(object $command): mixed
    {
        try {
            return $this->handle($command);
        } catch (HandlerFailedException $exception) {
            $wrapped = $exception->getWrappedExceptions();

            throw [] === $wrapped ? $exception : reset($wrapped);
        }
    }
}
