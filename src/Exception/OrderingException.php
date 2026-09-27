<?php

namespace App\Exception;

/**
 * A business rule of the order itself prevented it from being placed.
 */
abstract class OrderingException extends BusinessRuleViolation
{
}
