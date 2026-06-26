<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\ValueObjects;

use InvalidArgumentException;

abstract class StringValueObject
{
    protected string $value;

    public function __construct(string $value)
    {
        $this->validate($value);
        $this->value = $value;
    }

    abstract protected function validate(string $value): void;

    public function value(): string
    {
        return $this->value;
    }

    public function equals(StringValueObject $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
