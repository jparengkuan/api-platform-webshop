<?php

namespace App\MessageHandler;

use App\Entity\Customer;
use App\Message\InvoiceGenerated;
use App\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Mails the invoice PDF to the customer who placed the order. Runs in the Messenger worker, which
 * consumes the invoice_generated queue.
 */
#[AsMessageHandler]
final readonly class SendInvoiceEmailHandler
{
    /**
     * Creates the handler.
     */
    public function __construct(
        private CustomerRepository $customers,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $sender,
    ) {
    }

    /**
     * Sends the email. A failure to send is thrown, so Messenger retries it later.
     *
     * @throws UnrecoverableMessageHandlingException when the event can never be delivered; it isn't retried
     */
    public function __invoke(InvoiceGenerated $event): void
    {
        $customer = Uuid::isValid($event->customerId) ? $this->customers->find(Uuid::fromString($event->customerId)) : null;
        if (!$customer instanceof Customer || null === $customer->getEmail()) {
            throw new UnrecoverableMessageHandlingException(sprintf('No customer with an email address for invoice %s (customer %s).', $event->invoiceNumber, $event->customerId));
        }

        $pdf = base64_decode($event->pdfBase64, true);
        if (false === $pdf || '' === $pdf) {
            throw new UnrecoverableMessageHandlingException(sprintf('Invoice %s has no valid Base64 PDF.', $event->invoiceNumber));
        }

        $email = new TemplatedEmail()
            ->from(new Address($this->sender, 'Webshop'))
            ->to(new Address($customer->getEmail(), trim($customer->getFirstName().' '.$customer->getLastName())))
            ->subject(sprintf('Your invoice %s', $event->invoiceNumber))
            ->htmlTemplate('emails/invoice.html.twig')
            ->textTemplate('emails/invoice.txt.twig')
            ->context(['customer' => $customer, 'invoice' => $event])
            ->attach($pdf, $event->fileName, $event->contentType);

        $this->mailer->send($email);
        $this->logger->info('Mailed invoice {invoiceNumber} to customer {customerId}.', [
            'invoiceNumber' => $event->invoiceNumber,
            'customerId' => $event->customerId,
        ]);
    }
}
