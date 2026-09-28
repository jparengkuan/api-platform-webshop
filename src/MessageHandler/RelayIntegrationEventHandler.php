<?php

namespace App\MessageHandler;

use App\Message\IntegrationEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The relay of the transactional outbox: the worker reads an event from the outbox table and
 * publishes it to RabbitMQ with its routing key. If RabbitMQ is down, this throws and Messenger
 * retries later, so an event recorded with its transaction is always delivered (at least once).
 *
 * Only for events read from the outbox; events that arrive from RabbitMQ (e.g. InvoiceGenerated)
 * aren't sent back.
 */
#[AsMessageHandler(fromTransport: 'outbox')]
final readonly class RelayIntegrationEventHandler
{
    /**
     * Creates the relay.
     */
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * Publishes the event to the events transport (RabbitMQ).
     */
    public function __invoke(IntegrationEvent $event): void
    {
        $this->bus->dispatch($event, [
            new TransportNamesStamp(['events']),
            new AmqpStamp($event->routingKey()),
        ]);
    }
}
