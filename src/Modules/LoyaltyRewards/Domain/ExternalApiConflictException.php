<?php

namespace App\Modules\LoyaltyRewards\Domain;

final class ExternalApiConflictException extends \RuntimeException {
    public static function payloadMismatch(): self {
        return new self('Idempotency-Key ya fue usada con un payload diferente.');
    }

    public static function pendingRequest(): self {
        return new self('La solicitud idempotente anterior sigue pendiente de revision.');
    }
}
