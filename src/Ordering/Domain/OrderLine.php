<?php

namespace App\Ordering\Domain;

use ApiPlatform\Metadata\ApiProperty;
use App\Catalog\Domain\Product;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One product on an order. SKU, name and price are copied from the product when the order
 * is placed, so later catalog changes never alter what the customer bought.
 */
#[ORM\Entity]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['order:read'])]
    private ?Product $product;

    #[ORM\Column(length: 64)]
    #[Groups(['order:read'])]
    private string $sku;

    #[ORM\Column(length: 255)]
    #[Groups(['order:read'])]
    private string $productName;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'unit_price_')]
    #[ApiProperty(genId: false)]
    #[Groups(['order:read'])]
    private Money $unitPrice;

    #[ORM\Column]
    #[Groups(['order:read'])]
    private int $quantity;

    /**
     * Creates a line and copies the product's current SKU, name and price.
     *
     * @internal use {@see Order::place()}, which also reserves the stock
     *
     * @throws \InvalidArgumentException when the quantity is below 1
     * @throws \LogicException           when the product has no price
     */
    public function __construct(Order $order, Product $product, int $quantity)
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $price = $product->getPrice() ?? throw new \LogicException(sprintf('Product "%s" has no price.', $product->getSku()));

        $this->id = Uuid::v7();
        $this->order = $order;
        $this->product = $product;
        $this->sku = (string) $product->getSku();
        $this->productName = (string) $product->getName();
        $this->unitPrice = $price;
        $this->quantity = $quantity;
    }

    /**
     * Returns the unique identifier of this line.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the order this line belongs to.
     */
    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * Returns the ordered product, or null when it has since been deleted from the catalog.
     */
    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * Returns the SKU at the time the order was placed.
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * Returns the product name at the time the order was placed.
     */
    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * Returns the price per item at the time the order was placed.
     */
    public function getUnitPrice(): Money
    {
        return $this->unitPrice;
    }

    /**
     * Returns the number of items ordered.
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * Returns the unit price multiplied by the quantity.
     */
    #[ApiProperty(genId: false)]
    #[Groups(['order:read'])]
    public function getLineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
