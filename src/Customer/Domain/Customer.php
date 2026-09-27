<?php

namespace App\Customer\Domain;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Customer\Infrastructure\ApiPlatform\State\CurrentCustomerProvider;
use App\Customer\Infrastructure\ApiPlatform\State\CustomerRegistrationProcessor;
use App\Customer\Infrastructure\Doctrine\CustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.', groups: ['customer:register'])]
#[ApiResource(
    operations: [
        new Get(
            name: 'customer_get',
            security: "is_granted('ROLE_ADMIN') or object == user",
        ),
        new Post(
            uriTemplate: '/register',
            description: 'Register a new customer account.',
            denormalizationContext: ['groups' => ['customer:register']],
            validationContext: ['groups' => ['Default', 'customer:register']],
            processor: CustomerRegistrationProcessor::class,
            itemUriTemplate: '/customers/{id}',
        ),
        new Get(
            uriTemplate: '/me',
            description: 'The currently logged-in customer.',
            security: "is_granted('ROLE_USER')",
            normalizationContext: ['groups' => ['customer:read'], 'item_uri_template' => 'customer_get'],
            provider: CurrentCustomerProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['customer:read']],
)]
class Customer implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['customer:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['customer:read', 'customer:register'])]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['customer:read', 'customer:register'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['customer:read', 'customer:register'])]
    private ?string $lastName = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** The hashed password. */
    #[ORM\Column]
    private ?string $password = null;

    /** Only used on registration; hashed into $password and never persisted. */
    #[Assert\NotBlank(groups: ['customer:register'])]
    #[Assert\Length(min: 8, max: 4096, groups: ['customer:register'])]
    #[Assert\NotCompromisedPassword(skipOnError: true, groups: ['customer:register'])]
    #[Groups(['customer:register'])]
    private ?string $plainPassword = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['customer:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new customer with a time-ordered UUID and the current registration time.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Returns the unique identifier of this customer.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the normalized (trimmed, lowercase) email address.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Sets the email address, normalized to lowercase so logins are case-insensitive.
     */
    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    /**
     * Returns the customer's first name.
     */
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    /**
     * Sets the customer's first name.
     */
    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * Returns the customer's last name.
     */
    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    /**
     * Sets the customer's last name.
     */
    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    /**
     * Returns the email address, which identifies the customer when logging in.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Returns the granted roles; every customer has at least ROLE_USER.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Replaces the stored roles; ROLE_USER is always added implicitly.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Returns the hashed password.
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Sets the hashed password; never pass a plain-text password here.
     */
    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Returns the plain-text password submitted on registration, if any.
     */
    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    /**
     * Sets the plain-text password to be hashed on registration.
     */
    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    /**
     * Returns when the customer registered.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Removes the plain-text password from memory once it has been hashed.
     */
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }
}
