<?php

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Event\IntegrationEvent;
use App\Shared\Application\EventPublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Adapter: publishes integration events through Symfony Messenger to RabbitMQ, with the event's
 * routing key. The transport per event class is configured in config/packages/messenger.yaml.
 */
#[AsAlias(EventPublisherInterface::class)]
final readonly class MessengerEventPublisher implements EventPublisherInterface
{
    /**
     * Creates the publisher.
     */
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publishes the event; when the broker is unreachable the failure is logged and the event is lost.
     */
    public function publish(IntegrationEvent $event): void
    {
        try {
            $this->bus->dispatch($event, [new AmqpStamp($event->routingKey())]);
        } catch (MessengerException $exception) {
            $this->logger->error('Could not publish {event} ({routingKey}).', [
                'event' => $event::class,
                'routingKey' => $event->routingKey(),
                'exception' => $exception,
            ]);
        }
    }
}
