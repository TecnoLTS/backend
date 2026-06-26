<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\ValueObjects;

use BillingService\Shared\Domain\ValueObjects\StringValueObject;
use InvalidArgumentException;

class Environment extends StringValueObject
{
    public const PRUEBAS = '1';
    public const PRODUCCION = '2';

    private const VALID_ENVIRONMENTS = [
        self::PRUEBAS,
        self::PRODUCCION,
    ];

    protected function validate(string $value): void
    {
        if (!in_array($value, self::VALID_ENVIRONMENTS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Ambiente inválido: %s. Valores permitidos: %s',
                    $value,
                    implode(', ', self::VALID_ENVIRONMENTS)
                )
            );
        }
    }

    public function isPruebas(): bool
    {
        return $this->value === self::PRUEBAS;
    }

    public function isProduccion(): bool
    {
        return $this->value === self::PRODUCCION;
    }
}
