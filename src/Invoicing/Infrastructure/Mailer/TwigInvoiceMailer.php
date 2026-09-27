<?php

namespace App\Invoicing\Infrastructure\Mailer;

use App\Customer\Domain\Customer;
use App\Invoicing\Application\Event\InvoiceGenerated;
use App\Invoicing\Application\InvoiceMailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Adapter: an HTML and text email from the Twig templates in templates/emails/, sent with Symfony
 * Mailer (MAILER_DSN), with the invoice PDF attached.
 */
#[AsAlias(InvoiceMailerInterface::class)]
final readonly class TwigInvoiceMailer implements InvoiceMailerInterface
{
    /**
     * Creates the mailer.
     */
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $sender,
    ) {
    }

    /**
     * Sends the invoice email.
     */
    public function send(Customer $customer, InvoiceGenerated $invoice): void
    {
        $this->mailer->send(new TemplatedEmail()
            ->from(new Address($this->sender, 'Webshop'))
            ->to(new Address((string) $customer->getEmail(), trim($customer->getFirstName().' '.$customer->getLastName())))
            ->subject(sprintf('Your invoice %s', $invoice->invoiceNumber))
            ->htmlTemplate('emails/invoice.html.twig')
            ->textTemplate('emails/invoice.txt.twig')
            ->context(['customer' => $customer, 'invoice' => $invoice])
            ->attach($invoice->pdf(), $invoice->fileName, $invoice->contentType));
    }
}
