<?php

$root = dirname(__DIR__, 3);
$wallet = file_get_contents($root . '/Modules/LoyaltyRewards/Infrastructure/WalletNotificationProcessor.php');
$billing = file_get_contents(
    $root . '/Modules/Billing/Native/Billing/Infrastructure/Persistence/InvoiceRepository.php'
);
if (!is_string($wallet)
    || !str_contains($wallet, 'PARTITION BY tenant_id')
    || !str_contains($wallet, 'tenant_position ASC')
    || !str_contains($wallet, '$campaignQuota')
    || !str_contains($wallet, 'min($remaining, $campaignQuota)')) {
    throw new RuntimeException('Wallet campaign selection can starve another tenant/campaign');
}
if (!is_string($billing)
    || !preg_match(
        '/ROW_NUMBER\(\)\s+OVER\s*\(\s*PARTITION BY ih\.tenant_id/s',
        $billing
    )
    || !str_contains($billing, 'ih.tenant_id ASC')
    || !str_contains($billing, 'ih.access_key ASC')) {
    throw new RuntimeException('Billing recovery candidate selection is not tenant-fair/deterministic');
}

echo "Worker multi-tenant fairness: OK\n";
