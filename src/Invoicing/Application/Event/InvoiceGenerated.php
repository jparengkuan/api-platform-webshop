<?php

namespace App\Invoicing\Application\Event;

use App\Shared\Application\Event\IntegrationEvent;

/**
 * Integration event from the invoice service (services/invoice): the invoice PDF of an order is
 * ready. Consumed from the invoice_generated queue; the API never publishes it itself.
 */
final readonly class InvoiceGenerated implements IntegrationEvent
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

    /**
     * Returns the routing key on the webshop.events exchange.
     */
    public function routingKey(): string
    {
        return self::ROUTING_KEY;
    }

    /**
     * Returns the invoice PDF itself.
     *
     * @throws \UnexpectedValueException when the payload isn't valid Base64 or is empty
     */
    public function pdf(): string
    {
        $pdf = base64_decode($this->pdfBase64, true);
        if (false === $pdf || '' === $pdf) {
            throw new \UnexpectedValueException(sprintf('Invoice %s has no valid Base64 PDF.', $this->invoiceNumber));
        }

        return $pdf;
    }
}
