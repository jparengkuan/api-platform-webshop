<?php

namespace App\Shared\Domain\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * A request breaks a business rule of the domain (not enough stock, mixed currencies, ...).
 * API resources map it to HTTP 422; a new rule is a new subclass, the mapping stays the same.
 * Logged as info: these are expected outcomes, not application failures.
 */
#[WithLogLevel(LogLevel::INFO)]
abstract class BusinessRuleViolation extends \DomainException
{
}
