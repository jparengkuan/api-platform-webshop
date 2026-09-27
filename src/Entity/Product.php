<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\IriFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Embeddable\Money;
use App\Exception\InsufficientStockException;
use App\Exception\InvalidMoneyException;
use App\Exception\ProductUnavailableException;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[UniqueEntity(fields: ['sku'], message: 'A product with this SKU already exists.')]
#[ApiResource(
    operations: [
        new GetCollection(
            parameters: [
                // ?category=/api/categories/{id}
                'category' => new QueryParameter(filter: new IriFilter(), property: 'category', description: 'Only products in this category (IRI).'),
                // ?category.slug=kleding
                'category.slug' => new QueryParameter(filter: new ExactFilter(), property: 'category.slug', description: 'Only products in the category with this slug.'),
            ],
        ),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            // Money is an immutable value object: replace it instead of populating the existing instance.
            denormalizationContext: ['groups' => ['product:write'], 'deep_object_to_populate' => false],
        ),
    ],
    normalizationContext: ['groups' => ['product:read']],
    denormalizationContext: ['groups' => ['product:write']],
    exceptionToStatus: [InvalidMoneyException::class => 422],
    order: ['name' => 'ASC'],
)]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['product:read'])]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z0-9][A-Z0-9-]*$/', message: 'Use uppercase letters, digits and dashes.')]
    #[Assert\Length(max: 64)]
    #[Groups(['product:read', 'product:write'])]
    private ?string $sku = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['product:read', 'product:write'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read', 'product:write'])]
    private ?string $description = null;

    /** Sent as IRI when writing; read as an embedded object with name and slug. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['product:read', 'product:write'])]
    private ?Category $category = null;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'price_')]
    #[Assert\NotNull]
    #[Assert\Valid]
    #[ApiProperty(genId: false)]
    #[Groups(['product:read', 'product:write'])]
    private ?Money $price = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read', 'product:write'])]
    private int $stock = 0;

    #[ORM\Column]
    #[Groups(['product:read', 'product:write'])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['product:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new, active product with a time-ordered UUID.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Takes stock out of the inventory for an order line.
     *
     * @throws ProductUnavailableException when the product is not for sale
     * @throws InsufficientStockException  when fewer items are in stock than requested
     */
    public function reserveStock(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        if (!$this->active) {
            throw ProductUnavailableException::inactive((string) $this->sku);
        }

        if ($this->stock < $quantity) {
            throw new InsufficientStockException((string) $this->sku, $quantity, $this->stock);
        }

        $this->stock -= $quantity;
    }

    /**
     * Returns the unique identifier of this product.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the stock keeping unit, the human-readable product code.
     */
    public function getSku(): ?string
    {
        return $this->sku;
    }

    /**
     * Sets the stock keeping unit.
     */
    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    /**
     * Returns the display name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Sets the display name.
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns the optional long description.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Sets the optional long description.
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Returns the category the product belongs to.
     */
    public function getCategory(): ?Category
    {
        return $this->category;
    }

    /**
     * Moves the product to another category.
     */
    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Returns the current selling price.
     */
    public function getPrice(): ?Money
    {
        return $this->price;
    }

    /**
     * Replaces the selling price; existing orders keep the price they were placed with.
     */
    public function setPrice(Money $price): static
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Returns the number of items available for sale.
     */
    public function getStock(): int
    {
        return $this->stock;
    }

    /**
     * Sets the number of items available for sale, e.g. after a stock count.
     */
    public function setStock(int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    /**
     * Tells whether the product can be ordered.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Makes the product orderable or takes it out of sale.
     */
    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Returns when the product was added to the catalog.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
