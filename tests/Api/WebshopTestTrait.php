<?php

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures and auth helpers shared by the webshop API tests.
 *
 * @property Client $client
 */
trait WebshopTestTrait
{
    private const string PASSWORD = 'S3cure-webshop-pass!';

    /**
     * Empties all webshop tables so every test starts from a known state.
     */
    private function resetDatabase(): void
    {
        $this->entityManager()->getConnection()->executeStatement('TRUNCATE customer, product, customer_order, order_line CASCADE');
    }

    /**
     * Returns the entity manager of the currently booted kernel.
     */
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Creates a customer with the shared test password.
     *
     * @param list<string> $roles
     */
    private function createCustomer(string $email, array $roles = []): Customer
    {
        $customer = new Customer()
            ->setEmail($email)
            ->setFirstName('Jan')
            ->setLastName('Jansen')
            ->setRoles($roles);
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));

        $em = $this->entityManager();
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    /**
     * Creates a product directly in the database.
     */
    private function createProduct(string $sku, int $amount, int $stock, string $currency = 'EUR', bool $active = true): Product
    {
        $product = new Product()
            ->setSku($sku)
            ->setName('Product '.$sku)
            ->setPrice(new Money($amount, $currency))
            ->setStock($stock)
            ->setActive($active);

        $em = $this->entityManager();
        $em->persist($product);
        $em->flush();

        return $product;
    }

    /**
     * Reads the product straight from the database, bypassing the identity map.
     */
    private function reloadProduct(Product $product): Product
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->find(Product::class, $product->getId());
    }

    /**
     * Logs in through the API and returns the JWT.
     */
    private function login(string $email): string
    {
        $response = $this->client->request('POST', '/api/login', ['json' => ['email' => $email, 'password' => self::PASSWORD]]);
        self::assertResponseIsSuccessful();

        return $response->toArray()['token'];
    }

    /**
     * Returns the API IRI of a product, as clients send it in an order.
     */
    private function iri(Product $product): string
    {
        return '/api/products/'.$product->getId()->toRfc4122();
    }
}
