<?php

namespace App\Messenger;

use App\Message\IntegrationEvent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Adapter: records integration events in the transactional outbox (the Doctrine "outbox" transport,
 * see config/packages/messenger.yaml), in the same database transaction as the command that
 * raised them. {@see \App\MessageHandler\RelayIntegrationEventHandler} forwards them to RabbitMQ.
 */
#[AsAlias(EventPublisherInterface::class)]
final readonly class MessengerEventPublisher implements EventPublisherInterface
{
    /**
     * Creates the publisher.
     */
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * Stores the event in the outbox; a failure fails (and rolls back) the whole command.
     */
    public function publish(IntegrationEvent $event): void
    {
        $this->bus->dispatch($event);
    }
}
