<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Adapters;

use App\Modules\Billing\Application\Ports\BillingProductCatalogPort;
use App\Repositories\ProductRepository;

final class InProcessBillingProductCatalogAdapter implements BillingProductCatalogPort
{
    public function __construct(private readonly ProductRepository $repository = new ProductRepository())
    {
    }

    public function search(string $query, int $limit, array $options = []): array
    {
        return $this->repository->searchForBilling($query, $limit, $options);
    }

    public function find(string $productId, array $options = []): ?array
    {
        $product = $this->repository->getById($productId, $options);

        return is_array($product) ? $product : null;
    }

    public function skuExists(string $sku): bool
    {
        return $this->repository->skuExists($sku);
    }

    public function create(array $payload): array
    {
        return $this->repository->create($payload);
    }

    public function update(string $productId, array $payload): ?array
    {
        $product = $this->repository->update($productId, $payload);

        return is_array($product) ? $product : null;
    }
}
