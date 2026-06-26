<?php

namespace App\Modules\Billing\Application;

interface BillingGateway {
    public function emitInvoice(array $payload): array;
    public function health(): array;
    public function listRidePdfs(int $limit = 100, bool $includeCancelled = false): array;
    public function findRideBySourceReference(string $sourceReference): ?array;
    public function getInvoiceStatus(string $accessKey, ?string $ambiente = null): array;
    public function getInvoiceXml(string $accessKey, ?string $ambiente = null): array;
    public function getRidePdf(string $accessKey): array;
    public function sendMailTest(string $accessKey, ?string $ambiente = null): array;
    public function cancelAndReissueInvoice(string $accessKey, string $reason = '', ?string $ambiente = null): array;
    public function configuration(?string $ambiente = null): array;
    public function updateConfiguration(array $payload, ?string $ambiente = null): array;
    public function createBranch(array $payload, ?string $ambiente = null): array;
    public function updateBranch(int $branchId, array $payload, ?string $ambiente = null): array;
    public function uploadCertificate(string $filePath, string $fileName, string $certificatePassword, ?string $ambiente = null): array;
}
