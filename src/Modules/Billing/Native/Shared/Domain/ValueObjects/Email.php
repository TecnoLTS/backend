<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\ValueObjects;

use InvalidArgumentException;

class Email extends StringValueObject
{
    protected function validate(string $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                sprintf('Email inválido: %s', $value)
            );
        }
    }
}
