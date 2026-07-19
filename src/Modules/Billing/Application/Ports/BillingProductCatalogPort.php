<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Ports;

/**
 * Contract owned by Billing for the catalog capabilities it consumes.
 *
 * The implementation may be in-process in the modular monolith today and can
 * later become an HTTP/event adapter without changing Billing controllers.
 */
interface BillingProductCatalogPort
{
    public function search(string $query, int $limit, array $options = []): array;

    public function find(string $productId, array $options = []): ?array;

    public function skuExists(string $sku): bool;

    public function create(array $payload): array;

    public function update(string $productId, array $payload): ?array;
}
