<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

interface CommerceBillingHttpPort
{
    /** @return array{found:bool,http_status:int,invoice:?array} */
    public function findBySourceReference(
        string $tenantId,
        string $tenantHost,
        string $apiMode,
        string $sourceReference
    ): array;

    /** @return array{http_status:int,invoice:array} */
    public function emit(
        string $tenantId,
        string $tenantHost,
        string $apiMode,
        string $idempotencyKey,
        array $payload
    ): array;
}
