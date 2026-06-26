<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\Exceptions;

use BillingService\Shared\Domain\Exceptions\DomainException;

class SriException extends DomainException
{
    public static function connectionFailed(string $reason): self
    {
        return new self(sprintf('Error de conexión con el SRI: %s', $reason));
    }

    public static function invalidXml(string $reason): self
    {
        return new self(sprintf('XML inválido: %s', $reason));
    }

    public static function authorizationFailed(string $reason): self
    {
        return new self(sprintf('Autorización rechazada: %s', $reason));
    }

    public static function signatureFailed(string $reason): self
    {
        return new self(sprintf('Error en la firma digital: %s', $reason));
    }
}
