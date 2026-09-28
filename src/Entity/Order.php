<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use App\Dto\CheckoutOutput;
use App\Dto\PlaceOrderInput;
use App\Entity\Embeddable\Money;
use App\Enum\OrderStatus;
use App\Exception\BusinessRuleViolation;
use App\Exception\EmptyOrderException;
use App\Exception\MixedCurrencyException;
use App\Exception\OrderNotPayableException;
use App\Repository\OrderRepository;
use App\State\PlaceOrderProcessor;
use App\State\StartPaymentProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A customer's order. Aggregate root: lines are only created through {@see Order::place()},
 * so an order is always complete, priced in one currency and backed by reserved stock.
 *
 * Lifecycle: placed (awaiting payment) → paid, or → expired when it isn't paid in time, which puts
 * the reserved stock back.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'customer_order')]
#[ORM\Index(fields: ['customer', 'placedAt'])]
#[ORM\Index(fields: ['status', 'placedAt'])]
// One order per idempotency key per customer: a repeated request can never create a second order.
#[ORM\UniqueConstraint(fields: ['customer', 'idempotencyKey'])]
#[ApiResource(
    operations: [
        new GetCollection(
            description: 'Orders of the logged-in customer (all orders for admins).',
            security: "is_granted('ROLE_USER')",
        ),
        new Get(
            security: "is_granted('ORDER_VIEW', object)",
        ),
        new Post(
            description: 'Place an order for the logged-in customer.',
            security: "is_granted('ROLE_USER')",
            input: PlaceOrderInput::class,
            processor: PlaceOrderProcessor::class,
            // Two different carts sent with the same key at the same moment: the unique index lets one win.
            exceptionToStatus: [BusinessRuleViolation::class => 422, UniqueConstraintViolationException::class => 409],
            parameters: [
                'Idempotency-Key' => new HeaderParameter(
                    schema: ['type' => 'string', 'maxLength' => 255, 'example' => '4f9d1c2e-8a7b-4c3d-9e1f-0a2b3c4d5e6f'],
                    description: 'Optional. A key the client picks per cart (e.g. a UUID). Repeating the request with the same key returns the order already placed instead of a new one.',
                    required: false,
                    constraints: [
                        new Assert\Length(max: 255),
                        new Assert\Regex(pattern: '/^[!-~]+$/', message: 'Use visible ASCII characters only, e.g. a UUID.'),
                    ],
                ),
            ],
        ),
        new Post(
            uriTemplate: '/orders/{id}/payment',
            status: 201,
            description: 'Start paying the order: returns a Stripe Checkout URL to send the customer to.',
            security: "is_granted('ORDER_PAY', object)",
            read: true,
            deserialize: false,
            input: false,
            output: CheckoutOutput::class,
            processor: StartPaymentProcessor::class,
            normalizationContext: ['groups' => ['checkout:read']],
            exceptionToStatus: [BusinessRuleViolation::class => 422],
            name: 'order_payment',
        ),
    ],
    normalizationContext: ['groups' => ['order:read']],
    order: ['placedAt' => 'DESC'],
)]
class Order
{
    /** Minutes after placing within which a payment can be started. */
    public const int PAYMENT_WINDOW_MINUTES = 30;

    /**
     * Minutes after placing after which an unpaid order expires: the payment window, plus the 30
     * minutes a started Stripe Checkout stays valid, plus a margin for late webhooks.
     */
    public const int EXPIRES_AFTER_MINUTES = 75;

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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    /** The payment provider's id of the payment (a Stripe PaymentIntent), e.g. for refunds. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentReference = null;

    /** The client's Idempotency-Key of the request that placed the order, if it sent one. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

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
     * @param list<array{Product, positive-int}> $items          product and quantity pairs
     * @param string|null                        $idempotencyKey the client's key for this request, if it sent one
     *
     * @throws BusinessRuleViolation when a business rule prevents the order
     */
    public static function place(Customer $customer, array $items, \DateTimeImmutable $placedAt, ?string $idempotencyKey = null): self
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
        $order->idempotencyKey = $idempotencyKey;
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

    /**
     * Returns when the order was paid, or null while it isn't.
     */
    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    /**
     * Returns the client's Idempotency-Key of the request that placed the order, if any.
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Tells whether the order has exactly these quantities per product id, e.g. to check that a
     * repeated request is really the same one.
     *
     * @param array<string, int> $quantities quantity per RFC 4122 product id
     */
    public function hasQuantities(array $quantities): bool
    {
        $own = [];
        foreach ($this->lines as $line) {
            $own[(string) $line->getProduct()?->getId()->toRfc4122()] = $line->getQuantity();
        }
        ksort($own);
        ksort($quantities);

        return $own === $quantities;
    }

    /**
     * Returns the payment provider's id of the payment, or null while the order isn't paid.
     */
    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    /**
     * Returns until when a payment may be started; a started payment must finish within its own
     * time limit (Stripe: 30 minutes) after that.
     */
    public function payableUntil(): \DateTimeImmutable
    {
        return $this->placedAt->modify('+'.self::PAYMENT_WINDOW_MINUTES.' minutes');
    }

    /**
     * Checks that a payment for this order may be started now.
     *
     * @throws OrderNotPayableException when the order isn't awaiting payment or the window has closed
     */
    public function assertPayable(\DateTimeImmutable $now): void
    {
        if (OrderStatus::Placed !== $this->status) {
            throw OrderNotPayableException::notAwaitingPayment($this->status);
        }

        if ($now >= $this->payableUntil()) {
            throw OrderNotPayableException::paymentWindowClosed();
        }
    }

    /**
     * Records the payment. Idempotent: a payment provider may report the same payment more than once.
     *
     * @return bool true when the order became paid now, false when it already was
     *
     * @throws OrderNotPayableException when the order has expired in the meantime (the payment must be refunded)
     */
    public function markPaid(string $paymentReference, \DateTimeImmutable $paidAt): bool
    {
        if (OrderStatus::Paid === $this->status) {
            return false;
        }

        if (OrderStatus::Placed !== $this->status) {
            throw OrderNotPayableException::notAwaitingPayment($this->status);
        }

        $this->status = OrderStatus::Paid;
        $this->paidAt = $paidAt;
        $this->paymentReference = $paymentReference;

        return true;
    }

    /**
     * Expires an unpaid order and puts its reserved stock back. The products must be locked by the caller.
     *
     * @return bool true when the order expired now, false when it was already paid or expired
     */
    public function expire(): bool
    {
        if (OrderStatus::Placed !== $this->status) {
            return false;
        }

        $this->status = OrderStatus::Expired;
        foreach ($this->lines as $line) {
            // A product deleted since the order has no stock to return to.
            $line->getProduct()?->releaseStock($line->getQuantity());
        }

        return true;
    }
}
