<?php

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * A business rule prevented an order from being placed. Mapped to HTTP 422 on the order resource.
 * Logged as info: these are expected outcomes, not application failures.
 */
#[WithLogLevel(LogLevel::INFO)]
abstract class OrderingException extends \DomainException
{
}
