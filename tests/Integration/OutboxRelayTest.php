<?php

namespace App\Tests\Integration;

use App\Message\OrderPlaced;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The relay of the transactional outbox, as the worker runs it: an event read from the outbox is
 * published to RabbitMQ (the events transport) with its routing key.
 */
final class OutboxRelayTest extends KernelTestCase
{
    /**
     * An event received from the outbox is forwarded to the events transport, not back to the outbox.
     */
    public function testRelaysOutboxEventsToRabbitMq(): void
    {
        self::bootKernel();
        $event = new OrderPlaced('0199aaaa-0000-7000-8000-000000000001', '0199aaaa-0000-7000-8000-000000000002', new \DateTimeImmutable('2026-09-28 12:00'), 1250, 'EUR', []);

        self::getContainer()->get(MessageBusInterface::class)->dispatch($event, [new ReceivedStamp('outbox')]);

        $relayed = $this->transport('events')->getSent();
        self::assertCount(1, $relayed);
        self::assertSame($event, $relayed[0]->getMessage());
        self::assertSame(OrderPlaced::ROUTING_KEY, $relayed[0]->last(AmqpStamp::class)?->getRoutingKey());
        self::assertSame([], $this->transport('outbox')->getSent());
    }

    /**
     * Returns the in-memory stand-in of a transport.
     */
    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
