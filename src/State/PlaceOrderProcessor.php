<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\OrderLineInput;
use App\Dto\PlaceOrderInput;
use App\Entity\Customer;
use App\Entity\Order;
use App\Exception\BusinessRuleViolation;
use App\Ordering\PlaceOrder;
use App\Ordering\PlaceOrderHandler;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Adapts the API input to the {@see PlaceOrder} use case.
 *
 * @implements ProcessorInterface<PlaceOrderInput, Order>
 */
final readonly class PlaceOrderProcessor implements ProcessorInterface
{
    /**
     * Creates the processor.
     */
    public function __construct(
        private PlaceOrderHandler $placeOrder,
        private Security $security,
    ) {
    }

    /**
     * Places an order for the logged-in customer.
     *
     * @param PlaceOrderInput $data
     *
     * @throws AccessDeniedException when the user is not a customer
     * @throws BusinessRuleViolation when a business rule prevents the order
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Order
    {
        $customer = $this->security->getUser();
        if (!$customer instanceof Customer) {
            throw new AccessDeniedException('Only customers can place orders.');
        }

        return ($this->placeOrder)(new PlaceOrder(
            $customer,
            array_map($this->toLine(...), $data->lines),
            $this->idempotencyKey($context['request'] ?? null),
        ));
    }

    /**
     * Returns the request's Idempotency-Key header, or null when it wasn't sent. API Platform has
     * already validated it (the HeaderParameter on the operation).
     */
    private function idempotencyKey(?Request $request): ?string
    {
        $key = $request?->headers->get('Idempotency-Key');

        return null === $key || '' === $key ? null : $key;
    }

    /**
     * Converts a validated input line to a product id and quantity pair.
     *
     * @return array{Uuid, positive-int}
     *
     * @throws \LogicException when the line has not been validated
     */
    private function toLine(OrderLineInput $line): array
    {
        if (null === $line->product || null === $line->quantity || $line->quantity < 1) {
            throw new \LogicException('Order lines must be validated before the order is processed.');
        }

        return [$line->product->getId(), $line->quantity];
    }
}
