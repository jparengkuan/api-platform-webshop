<?php

namespace App\Tests\Unit\Messenger;

use App\Message\IntegrationEvent;
use App\Messenger\MessengerEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerEventPublisherTest extends TestCase
{
    /**
     * The event goes onto the bus as is; the routing sends it to the outbox.
     */
    public function testDispatchesTheEvent(): void
    {
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
            return $dispatched[] = Envelope::wrap($message, $stamps);
        });

        new MessengerEventPublisher($bus)->publish($event = $this->event());

        self::assertCount(1, $dispatched);
        self::assertSame($event, $dispatched[0]->getMessage());
    }

    /**
     * When the outbox can't be written, the command must fail (and roll back), not lose the event.
     */
    public function testFailureToRecordIsThrown(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('Database gone'));

        $this->expectException(TransportException::class);
        new MessengerEventPublisher($bus)->publish($this->event());
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
