<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CheckoutOutput;
use App\Entity\Order;
use App\Exception\OrderNotPayableException;
use App\Ordering\StartPaymentHandler;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Adapts POST /api/orders/{id}/payment to the {@see StartPaymentHandler} use case.
 *
 * @implements ProcessorInterface<Order, CheckoutOutput>
 */
final readonly class StartPaymentProcessor implements ProcessorInterface
{
    /**
     * Creates the processor.
     */
    public function __construct(private StartPaymentHandler $startPayment)
    {
    }

    /**
     * Starts the payment of the order that API Platform loaded (and the security expression approved).
     *
     * @param Order $data
     *
     * @throws OrderNotPayableException        when the order can't be paid (422)
     * @throws ServiceUnavailableHttpException when the payment provider can't be reached (503)
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CheckoutOutput
    {
        try {
            $checkout = ($this->startPayment)($data);
        } catch (\RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(30, 'The payment provider is not available; try again shortly.', $exception);
        }

        return new CheckoutOutput($checkout->url, $checkout->id, $checkout->expiresAt);
    }
}
