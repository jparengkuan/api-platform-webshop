<?php

namespace App\Shared\Domain\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

#[WithLogLevel(LogLevel::INFO)]
final class InvalidMoneyException extends \InvalidArgumentException
{
    /**
     * Creates the exception for a negative amount.
     */
    public static function negative(int $amount): self
    {
        return new self(sprintf('Money cannot be negative, got %d.', $amount));
    }

    /**
     * Creates the exception for arithmetic on two different currencies.
     */
    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(sprintf('Cannot combine %s with %s.', $expected, $actual));
    }
}
