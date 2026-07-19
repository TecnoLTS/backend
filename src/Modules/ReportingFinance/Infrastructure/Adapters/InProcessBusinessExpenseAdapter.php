<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure\Adapters;

use App\Modules\ReportingFinance\Application\Ports\BusinessExpensePort;
use App\Repositories\BusinessExpenseRepository;

final class InProcessBusinessExpenseAdapter implements BusinessExpensePort
{
    public function __construct(private readonly BusinessExpenseRepository $repository = new BusinessExpenseRepository())
    {
    }

    public function list(array $filters = []): array
    {
        return $this->repository->list($filters);
    }

    public function categories(): array
    {
        return $this->repository->categories();
    }

    public function create(array $data, string $userId): array
    {
        return $this->repository->create($data, $userId);
    }

    public function update(string $id, array $data): array
    {
        return $this->repository->update($id, $data);
    }

    public function updateStatus(string $id, string $status, array $data = [], ?string $userId = null): array
    {
        return $this->repository->updateStatus($id, $status, $data, $userId);
    }

    public function summary(array $options = []): array
    {
        return $this->repository->summary($options);
    }

    public function listRecurrences(): array
    {
        return $this->repository->listRecurrences();
    }

    public function createRecurrence(array $data, string $userId): array
    {
        return $this->repository->createRecurrence($data, $userId);
    }

    public function updateRecurrence(string $id, array $data): array
    {
        return $this->repository->updateRecurrence($id, $data);
    }

    public function deleteRecurrence(string $id): array
    {
        return $this->repository->deleteRecurrence($id);
    }
}
