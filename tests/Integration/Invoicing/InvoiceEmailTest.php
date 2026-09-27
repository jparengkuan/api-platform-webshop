<?php

namespace App\Tests\Integration\Invoicing;

use App\Invoicing\Application\Event\InvoiceGenerated;
use App\Invoicing\Infrastructure\Messenger\InvoiceEventSerializer;
use App\Tests\Support\DatabaseFixturesTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Mime\Email;

/**
 * The mailer worker's path: a message from the invoice service, as it comes off the invoice_generated
 * queue, is decoded and mailed to the customer with the PDF attached.
 */
final class InvoiceEmailTest extends KernelTestCase
{
    use DatabaseFixturesTrait;

    private const string PDF = "%PDF-1.3\nnot a real invoice\n%%EOF";

    /**
     * Starts every test with an empty database.
     */
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    /**
     * The customer gets one email with the invoice PDF attached.
     */
    public function testInvoiceIsMailedToTheCustomer(): void
    {
        $customer = $this->createCustomer('jan@example.com');

        $this->consume($this->messageFromInvoiceService($customer->getId()->toRfc4122()));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'jan@example.com');
        self::assertEmailAddressContains($email, 'From', 'webshop@example.com');
        self::assertEmailSubjectContains($email, 'INV-0199aaaa-0000-7000-8000-000000000001');
        self::assertEmailTextBodyContains($email, 'Dear '.$customer->getFirstName());
        self::assertEmailAttachmentCount($email, 1);

        $attachment = $email->getAttachments()[0];
        self::assertSame('INV-0199aaaa-0000-7000-8000-000000000001.pdf', $attachment->getFilename());
        self::assertSame('application/pdf', $attachment->getContentType());
        self::assertSame(self::PDF, $attachment->getBody());
    }

    /**
     * An invoice for an unknown customer can never be delivered: it fails without a retry and sends nothing.
     */
    public function testUnknownCustomerIsNotRetried(): void
    {
        try {
            $this->consume($this->messageFromInvoiceService('0199aaaa-0000-7000-8000-00000000dead'));
            self::fail('The handler must reject an invoice for an unknown customer.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $exception->getPrevious());
        }

        self::assertEmailCount(0);
    }

    /**
     * A retry sends the event back to RabbitMQ; it must come back as the same event.
     */
    public function testEventSurvivesARetry(): void
    {
        $serializer = self::getContainer()->get(InvoiceEventSerializer::class);
        $event = $serializer->decode($this->messageFromInvoiceService('0199aaaa-0000-7000-8000-000000000002'))->getMessage();

        $encoded = $serializer->encode(new Envelope($event));

        self::assertSame('invoice.generated', $encoded['headers']['type'] ?? null);
        self::assertEquals($event, $serializer->decode($encoded)->getMessage());
    }

    /**
     * The message exactly as the Python invoice service publishes it.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    private function messageFromInvoiceService(string $customerId): array
    {
        return [
            'body' => json_encode([
                'invoiceNumber' => 'INV-0199aaaa-0000-7000-8000-000000000001',
                'orderId' => '0199aaaa-0000-7000-8000-000000000001',
                'customerId' => $customerId,
                'generatedAt' => '2026-09-28T12:00:00+00:00',
                'fileName' => 'INV-0199aaaa-0000-7000-8000-000000000001.pdf',
                'contentType' => 'application/pdf',
                'pdfBase64' => base64_encode(self::PDF),
            ], \JSON_THROW_ON_ERROR),
            'headers' => ['type' => InvoiceGenerated::ROUTING_KEY, 'content_type' => 'application/json'],
        ];
    }

    /**
     * Decodes and handles the message the way messenger:consume does.
     *
     * @param array{body: string, headers: array<string, string>} $message
     */
    private function consume(array $message): void
    {
        $envelope = self::getContainer()->get(InvoiceEventSerializer::class)->decode($message);
        self::assertInstanceOf(InvoiceGenerated::class, $envelope->getMessage());

        self::getContainer()->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('invoices')));
    }
}
