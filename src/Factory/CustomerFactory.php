<?php

namespace App\Factory;

use App\Entity\Customer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Symfony\Component\String\u;
use function Zenstruck\Foundry\force;

/**
 * Creates customers with realistic Dutch names. Every customer can log in with {@see self::PASSWORD}.
 *
 * @extends PersistentObjectFactory<Customer>
 */
final class CustomerFactory extends PersistentObjectFactory
{
    /** Plain-text password of every generated customer; development and tests only. */
    public const string PASSWORD = 'password';

    /**
     * Creates the factory.
     */
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
    }

    /**
     * Returns the class this factory creates.
     */
    public static function class(): string
    {
        return Customer::class;
    }

    /**
     * Grants the customer administrator rights.
     */
    public function admin(): self
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }

    /**
     * Returns realistic default values; any of them can be overridden per call.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $firstName = self::faker()->firstName();
        $lastName = self::faker()->lastName();

        // The email matches the name, e.g. "Floris de Vries" becomes "floris.devries123@gmail.com".
        $localPart = u($firstName.'.'.$lastName)->ascii()->lower()->replaceMatches('/[^a-z.]/', '');

        return [
            'email' => sprintf('%s%d@%s', $localPart, self::faker()->unique()->numberBetween(1, 9999), self::faker()->freeEmailDomain()),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'roles' => [],
            'createdAt' => force(\DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-2 years', '-1 week'))),
        ];
    }

    /**
     * Hashes the shared password onto every new customer.
     */
    protected function initialize(): static
    {
        return $this->afterInstantiate(function (Customer $customer): void {
            $customer->setPassword($this->passwordHash($customer));
        });
    }

    /**
     * Returns the hash of {@see self::PASSWORD}; hashing is deliberately slow, so it is computed once per process.
     */
    private function passwordHash(Customer $customer): string
    {
        static $hash = null;

        return $hash ??= $this->passwordHasher->hashPassword($customer, self::PASSWORD);
    }
}
