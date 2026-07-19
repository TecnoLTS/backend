<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Application\Ports;

interface BusinessExpensePort
{
    public function list(array $filters = []): array;

    public function categories(): array;

    public function create(array $data, string $userId): array;

    public function update(string $id, array $data): array;

    public function updateStatus(string $id, string $status, array $data = [], ?string $userId = null): array;

    public function summary(array $options = []): array;

    public function listRecurrences(): array;

    public function createRecurrence(array $data, string $userId): array;

    public function updateRecurrence(string $id, array $data): array;

    public function deleteRecurrence(string $id): array;
}
