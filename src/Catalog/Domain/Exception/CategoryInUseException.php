<?php

namespace App\Catalog\Domain\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * The category can't be deleted while products still belong to it. Mapped to HTTP 409.
 */
#[WithLogLevel(LogLevel::INFO)]
final class CategoryInUseException extends \DomainException
{
    /**
     * Creates the exception for the given category name.
     */
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Category "%s" still has products. Move them to another category first.', $name));
    }
}
