<?php

namespace App\Modules\LoyaltyRewards\Domain;

final class ExternalApiAccessException extends \RuntimeException {
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $errorCode
    ) {
        parent::__construct($message);
    }

    public static function authenticationRequired(): self {
        return new self('Clave API requerida.', 401, 'LOYALTY_API_AUTH_REQUIRED');
    }

    public static function invalidCredential(): self {
        return new self('Clave API no autorizada.', 401, 'LOYALTY_API_CREDENTIAL_INVALID');
    }

    public static function insufficientScope(): self {
        return new self('El cliente API no tiene permisos para esta operacion.', 403, 'LOYALTY_API_SCOPE_FORBIDDEN');
    }

    public static function rateLimitExceeded(): self {
        return new self('El cliente API excedio su limite de solicitudes por minuto.', 429, 'LOYALTY_API_RATE_LIMITED');
    }

    public function httpStatus(): int {
        return $this->httpStatus;
    }

    public function errorCode(): string {
        return $this->errorCode;
    }
}
