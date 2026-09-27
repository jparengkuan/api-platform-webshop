<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Customer;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Provides the customer attached to the current JWT.
 *
 * @implements ProviderInterface<Customer>
 */
final readonly class CurrentCustomerProvider implements ProviderInterface
{
    /**
     * Creates the provider.
     */
    public function __construct(private Security $security)
    {
    }

    /**
     * Returns the logged-in customer, or null (404) when the user is not a customer.
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Customer
    {
        $user = $this->security->getUser();

        return $user instanceof Customer ? $user : null;
    }
}
