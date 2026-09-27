<?php

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\InvalidMoneyException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An amount of money in minor units (cents), so calculations never suffer from float rounding.
 */
#[ORM\Embeddable]
final readonly class Money
{
    /**
     * Creates an amount of money.
     *
     * @param int    $amount   amount in minor units, e.g. 1250 for € 12,50
     * @param string $currency ISO 4217 currency code, e.g. "EUR"
     *
     * @throws InvalidMoneyException when the amount is negative
     */
    public function __construct(
        #[ORM\Column]
        #[Assert\PositiveOrZero]
        #[Groups(['product:read', 'product:write', 'order:read'])]
        public int $amount,

        #[ORM\Column(length: 3)]
        #[Assert\NotBlank]
        #[Assert\Currency]
        #[Groups(['product:read', 'product:write', 'order:read'])]
        public string $currency,
    ) {
        if ($amount < 0) {
            throw InvalidMoneyException::negative($amount);
        }
    }

    /**
     * Creates a zero amount in the given currency, the starting point for totals.
     */
    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Returns the sum of this and another amount as a new instance.
     *
     * @throws InvalidMoneyException when the currencies differ
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Returns this amount multiplied by a factor as a new instance.
     *
     * @throws InvalidMoneyException when the factor is negative
     */
    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Tells whether both amounts are in the same currency.
     */
    public function hasSameCurrencyAs(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /**
     * Tells whether both amounts have the same value and currency.
     */
    public function equals(self $other): bool
    {
        return $this->hasSameCurrencyAs($other) && $this->amount === $other->amount;
    }

    /**
     * Guards arithmetic against combining different currencies.
     *
     * @throws InvalidMoneyException when the currencies differ
     */
    private function assertSameCurrency(self $other): void
    {
        if (!$this->hasSameCurrencyAs($other)) {
            throw InvalidMoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }
}
