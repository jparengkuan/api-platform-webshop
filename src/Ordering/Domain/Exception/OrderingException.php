<?php

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\Exception\BusinessRuleViolation;

/**
 * A business rule of the order itself prevented it from being placed.
 */
abstract class OrderingException extends BusinessRuleViolation
{
}
