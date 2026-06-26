<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\Exceptions;

class ValidationException extends DomainException
{
    public static function fromField(string $field, string $reason): self
    {
        return new self(sprintf('Validation error in field "%s": %s', $field, $reason));
    }
}
