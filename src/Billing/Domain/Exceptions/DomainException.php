<?php

namespace Domain\Billing\Domain\Exceptions;

use RuntimeException;

/** @phpstan-consistent-constructor */
class DomainException extends RuntimeException
{
    public static function because(string $reason): static
    {
        return new static($reason);
    }
}
