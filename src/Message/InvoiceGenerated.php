<?php

namespace App\Message;

/**
 * Integration event from the invoice service (services/invoice): the invoice PDF of an order is
 * ready. Consumed from the invoice_generated queue; the API never publishes it itself.
 */
final readonly class InvoiceGenerated
{
    /** Routing key on the webshop.events exchange. */
    public const string ROUTING_KEY = 'invoice.generated';

    /**
     * Creates the event.
     *
     * @param string $pdfBase64 the invoice PDF, Base64 encoded
     */
    public function __construct(
        public string $invoiceNumber,
        public string $orderId,
        public string $customerId,
        public \DateTimeImmutable $generatedAt,
        public string $fileName,
        public string $contentType,
        public string $pdfBase64,
    ) {
    }
}
