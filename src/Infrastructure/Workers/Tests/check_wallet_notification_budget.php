<?php

$processor = file_get_contents(
    dirname(__DIR__, 3) . '/Modules/LoyaltyRewards/Infrastructure/WalletNotificationProcessor.php'
);
$script = file_get_contents(dirname(__DIR__, 3) . '/../scripts/process_wallet_notifications.php');
if (!is_string($processor)
    || !str_contains($processor, '$recipientBudget - $tally[\'processed\']')
    || !str_contains($processor, '$deadlineMonotonic')
    || !str_contains($processor, '$tally[\'processed\'] < $recipientBudget')
    || !str_contains($processor, 'LIMIT " . min($recipientBudget, 1000)')
    || !str_contains($processor, 'PARTITION BY tenant_id')
    || !str_contains($processor, '$campaignQuota')
    || !str_contains($processor, 'min($remaining, $campaignQuota)')) {
    throw new RuntimeException('Wallet worker does not enforce a global recipient/deadline budget');
}
if (!is_string($script)
    || !str_contains($script, "'max-seconds::'")
    || !str_contains($script, '$deadlineMonotonic')
    || !str_contains($script, "'budget_consumed'")) {
    throw new RuntimeException('Wallet CLI does not expose and report its cooperative deadline');
}

echo "Wallet notification work budget: OK\n";
