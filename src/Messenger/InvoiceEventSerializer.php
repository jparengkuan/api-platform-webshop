<?php

namespace App\Messenger;

use App\Message\InvoiceGenerated;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * Reads the events of the Python invoice service: plain JSON with a "type: invoice.generated" header,
 * instead of the PHP class name that Symfony's own serializer expects.
 */
final readonly class InvoiceEventSerializer implements SerializerInterface
{
    private Serializer $serializer;

    /**
     * Creates the serializer on top of the application's Serializer.
     */
    public function __construct(SymfonySerializerInterface $serializer)
    {
        $this->serializer = new Serializer($serializer, 'json', [], [
            InvoiceGenerated::ROUTING_KEY => InvoiceGenerated::class,
        ]);
    }

    /**
     * Turns a message from the queue into an envelope with an {@see InvoiceGenerated} event.
     *
     * @param array{body: string, headers?: array<string, mixed>} $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        return $this->serializer->decode($encodedEnvelope);
    }

    /**
     * Encodes the event in the same form, e.g. when Messenger sends it back to the queue for a retry.
     *
     * @return array{body: string, headers?: array<string, mixed>}
     */
    public function encode(Envelope $envelope): array
    {
        return $this->serializer->encode($envelope);
    }
}
