<?php

namespace App\Invoicing\Application;

use App\Customer\Domain\Customer;
use App\Invoicing\Application\Event\InvoiceGenerated;

/**
 * Port for delivering an invoice to the customer who placed the order.
 */
interface InvoiceMailerInterface
{
    /**
     * Sends the invoice PDF to the customer.
     *
     * @throws \RuntimeException when the email can't be sent now; sending again later may work
     */
    public function send(Customer $customer, InvoiceGenerated $invoice): void;
}
