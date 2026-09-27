<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Customer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hashes the plain password before the new customer is persisted.
 *
 * @implements ProcessorInterface<Customer, Customer>
 */
final readonly class CustomerRegistrationProcessor implements ProcessorInterface
{
    /**
     * Creates the processor, decorating API Platform's Doctrine persist processor.
     *
     * @param ProcessorInterface<Customer, Customer> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Hashes the plain password, erases it and persists the new customer.
     *
     * @param Customer $data
     *
     * @throws \LogicException when called without a validated plain password
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Customer
    {
        $plainPassword = $data->getPlainPassword() ?? throw new \LogicException('The plain password must be validated before registration is processed.');

        $data->setPassword($this->passwordHasher->hashPassword($data, $plainPassword));
        $data->eraseCredentials();

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
