<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\ValueObjects;

use BillingService\Shared\Domain\ValueObjects\StringValueObject;
use InvalidArgumentException;

class DocumentType extends StringValueObject
{
    public const FACTURA = '01';
    public const NOTA_CREDITO = '04';
    public const NOTA_DEBITO = '05';
    public const GUIA_REMISION = '06';
    public const RETENCION = '07';

    private const VALID_TYPES = [
        self::FACTURA,
        self::NOTA_CREDITO,
        self::NOTA_DEBITO,
        self::GUIA_REMISION,
        self::RETENCION,
    ];

    protected function validate(string $value): void
    {
        if (!in_array($value, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Tipo de documento inválido: %s. Valores permitidos: %s',
                    $value,
                    implode(', ', self::VALID_TYPES)
                )
            );
        }
    }

    public function isFactura(): bool
    {
        return $this->value === self::FACTURA;
    }
}
