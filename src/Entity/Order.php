<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\PlaceOrderInput;
use App\Entity\Embeddable\Money;
use App\Enum\OrderStatus;
use App\Exception\BusinessRuleViolation;
use App\Exception\EmptyOrderException;
use App\Exception\MixedCurrencyException;
use App\Repository\OrderRepository;
use App\State\PlaceOrderProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's order. Aggregate root: lines are only created through {@see Order::place()},
 * so an order is always complete, priced in one currency and backed by reserved stock.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'customer_order')]
#[ORM\Index(fields: ['customer', 'placedAt'])]
#[ApiResource(
    operations: [
        new GetCollection(
            description: 'Orders of the logged-in customer (all orders for admins).',
            security: "is_granted('ROLE_USER')",
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and object.isPlacedBy(user))",
        ),
        new Post(
            description: 'Place an order for the logged-in customer.',
            security: "is_granted('ROLE_USER')",
            input: PlaceOrderInput::class,
            processor: PlaceOrderProcessor::class,
            exceptionToStatus: [BusinessRuleViolation::class => 422],
        ),
    ],
    normalizationContext: ['groups' => ['order:read']],
    order: ['placedAt' => 'DESC'],
)]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['order:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order:read'])]
    private Customer $customer;

    #[ORM\Column(enumType: OrderStatus::class)]
    #[Groups(['order:read'])]
    private OrderStatus $status;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ApiProperty(genId: false)]
    #[Groups(['order:read'])]
    private Collection $lines;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'total_')]
    #[ApiProperty(genId: false)]
    #[Groups(['order:read'])]
    private Money $total;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['order:read'])]
    private \DateTimeImmutable $placedAt;

    /**
     * Creates an empty order; use {@see Order::place()} to create a valid one.
     */
    private function __construct(Customer $customer, \DateTimeImmutable $placedAt)
    {
        $this->id = Uuid::v7();
        $this->customer = $customer;
        $this->status = OrderStatus::Placed;
        $this->placedAt = $placedAt;
        $this->lines = new ArrayCollection();
    }

    /**
     * Places an order and reserves stock for every line.
     *
     * @param list<array{Product, positive-int}> $items product and quantity pairs
     *
     * @throws BusinessRuleViolation when a business rule prevents the order
     */
    public static function place(Customer $customer, array $items, \DateTimeImmutable $placedAt): self
    {
        if ([] === $items) {
            throw new EmptyOrderException();
        }

        $currency = $items[0][0]->getPrice()?->currency;
        foreach ($items as [$product]) {
            if ($product->getPrice()?->currency !== $currency) {
                throw new MixedCurrencyException();
            }
        }

        $order = new self($customer, $placedAt);
        $order->total = Money::zero((string) $currency);

        foreach ($items as [$product, $quantity]) {
            $product->reserveStock($quantity);
            $line = new OrderLine($order, $product, $quantity);
            $order->lines->add($line);
            $order->total = $order->total->add($line->getLineTotal());
        }

        return $order;
    }

    /**
     * Tells whether the given user is the customer who placed this order.
     */
    public function isPlacedBy(?UserInterface $user): bool
    {
        return $user instanceof Customer && $user->getId()->equals($this->customer->getId());
    }

    /**
     * Returns the unique identifier of this order.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the customer who placed the order.
     */
    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * Returns the current status in the order lifecycle.
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /** @return list<OrderLine> */
    /**
     * Returns the ordered products.
     *
     * @return list<OrderLine>
     */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /**
     * Returns the sum of all line totals.
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    /**
     * Returns when the order was placed.
     */
    public function getPlacedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }
}
