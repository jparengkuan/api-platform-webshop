<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Embeddable\Money;
use App\Exception\InvalidMoneyException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * Addition and multiplication return new instances and leave the original untouched.
     */
    public function testArithmeticReturnsNewInstances(): void
    {
        $price = new Money(1250, 'EUR');

        self::assertTrue($price->add(new Money(50, 'EUR'))->equals(new Money(1300, 'EUR')));
        self::assertTrue($price->multiply(3)->equals(new Money(3750, 'EUR')));
        self::assertSame(1250, $price->amount);
    }

    /**
     * Two amounts are only equal when both value and currency match.
     */
    public function testEqualsComparesAmountAndCurrency(): void
    {
        self::assertTrue(Money::zero('EUR')->equals(new Money(0, 'EUR')));
        self::assertFalse(new Money(100, 'EUR')->equals(new Money(100, 'USD')));
    }

    /**
     * A negative amount cannot be created.
     */
    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(InvalidMoneyException::class);

        new Money(-1, 'EUR');
    }

    /**
     * Adding different currencies fails instead of producing a wrong total.
     */
    public function testDifferentCurrenciesCannotBeAdded(): void
    {
        $this->expectException(InvalidMoneyException::class);

        new Money(100, 'EUR')->add(new Money(100, 'USD'));
    }
}
