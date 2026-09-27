<?php

namespace App\Tests\Unit\Messenger;

use App\Message\IntegrationEvent;
use App\Messenger\MessengerEventPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerEventPublisherTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * The event is dispatched with its own routing key, so RabbitMQ routes it to the right queues.
     */
    public function testDispatchesWithTheEventsRoutingKey(): void
    {
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
            return $dispatched[] = Envelope::wrap($message, $stamps);
        });

        $this->publisher($bus)->publish($event = $this->event());

        self::assertCount(1, $dispatched);
        self::assertSame($event, $dispatched[0]->getMessage());
        self::assertSame('thing.happened', $dispatched[0]->last(AmqpStamp::class)?->getRoutingKey());
    }

    /**
     * An unreachable broker doesn't fail the caller; the failure is logged.
     */
    public function testUnreachableBrokerIsLoggedNotThrown(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('Connection refused'));

        $this->publisher($bus)->publish($this->event());

        self::assertSame(['Could not publish {event} ({routingKey}).'], $this->errors);
    }

    /**
     * Creates the publisher with a logger that records errors in this test case.
     */
    private function publisher(MessageBusInterface $bus): MessengerEventPublisher
    {
        $logger = new class(function (string $message): void {
            $this->errors[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        };

        return new MessengerEventPublisher($bus, $logger);
    }

    /**
     * Returns an arbitrary integration event.
     */
    private function event(): IntegrationEvent
    {
        return new class implements IntegrationEvent {
            public function routingKey(): string
            {
                return 'thing.happened';
            }
        };
    }
}
