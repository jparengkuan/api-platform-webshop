<?php

namespace App\MessageHandler;

use App\Mailer\InvoiceMailerInterface;
use App\Message\InvoiceGenerated;
use App\Repository\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Use case: mail the invoice PDF to the customer who placed the order. Runs in the Messenger
 * worker, which consumes the invoice_generated queue.
 */
#[AsMessageHandler]
final readonly class SendInvoiceEmailHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private CustomerRepositoryInterface $customers,
        private InvoiceMailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the invoice. A failure to send is thrown, so Messenger retries it later.
     *
     * @throws UnrecoverableMessageHandlingException when the invoice can never be delivered; it isn't retried
     */
    public function __invoke(InvoiceGenerated $invoice): void
    {
        $customer = Uuid::isValid($invoice->customerId) ? $this->customers->findById(Uuid::fromString($invoice->customerId)) : null;
        if (null === $customer?->getEmail()) {
            throw new UnrecoverableMessageHandlingException(sprintf('No customer with an email address for invoice %s (customer %s).', $invoice->invoiceNumber, $invoice->customerId));
        }

        try {
            $invoice->pdf();
        } catch (\UnexpectedValueException $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), previous: $exception);
        }

        $this->mailer->send($customer, $invoice);

        $this->logger->info('Mailed invoice {invoiceNumber} to customer {customerId}.', [
            'invoiceNumber' => $invoice->invoiceNumber,
            'customerId' => $invoice->customerId,
        ]);
    }
}
