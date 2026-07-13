<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    'account_balance_mismatches' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_point_accounts a
        LEFT JOIN (
            SELECT tenant_id, member_id, COALESCE(SUM(points), 0) AS ledger_balance
            FROM loyalty_point_ledger
            GROUP BY tenant_id, member_id
        ) l ON l.tenant_id = a.tenant_id AND l.member_id = a.member_id
        WHERE a.balance <> COALESCE(l.ledger_balance, 0)
        SQL,
    'account_debt_mismatches' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_point_accounts a
        LEFT JOIN (
            SELECT tenant_id, member_id, COALESCE(SUM(points), 0) AS ledger_debt
            FROM loyalty_debt_ledger
            GROUP BY tenant_id, member_id
        ) d ON d.tenant_id = a.tenant_id AND d.member_id = a.member_id
        WHERE a.points_debt <> COALESCE(d.ledger_debt, 0)
        SQL,
    'negative_accounts' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_point_accounts
        WHERE balance < 0 OR points_debt < 0 OR lifetime_points < 0
        SQL,
    'negative_reward_stock' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_rewards
        WHERE stock < 0
        SQL,
    'duplicate_active_purchase_references' => <<<'SQL'
        SELECT COUNT(*)
        FROM (
            SELECT tenant_id, normalized_reference
            FROM loyalty_point_ledger
            WHERE entry_type = 'purchase'
              AND reversed_at IS NULL
              AND normalized_reference IS NOT NULL
              AND normalized_reference <> ''
            GROUP BY tenant_id, normalized_reference
            HAVING COUNT(*) > 1
        ) duplicates
        SQL,
    'reversal_total_mismatches' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_reversals r
        JOIN loyalty_point_ledger purchase
          ON purchase.tenant_id = r.tenant_id
         AND purchase.id = r.ledger_id
        WHERE purchase.entry_type <> 'purchase'
           OR purchase.points <> r.points_reversed + r.debt_created
        SQL,
    'orphan_redemptions' => <<<'SQL'
        SELECT COUNT(*)
        FROM loyalty_redemptions r
        LEFT JOIN loyalty_members m
          ON m.tenant_id = r.tenant_id AND m.id = r.member_id
        LEFT JOIN loyalty_rewards reward
          ON reward.tenant_id = r.tenant_id AND reward.id = r.reward_id
        LEFT JOIN loyalty_point_accounts account
          ON account.tenant_id = r.tenant_id AND account.member_id = r.member_id
        WHERE m.id IS NULL OR reward.id IS NULL OR account.member_id IS NULL
        SQL,
];

$results = [];
$pdo->beginTransaction();
try {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    foreach ($queries as $name => $query) {
        $results[$name] = (int)$pdo->query($query)->fetchColumn();
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$failed = array_keys(array_filter($results, static fn(int $count): bool => $count !== 0));
$report = [
    'checkedAt' => gmdate(DATE_ATOM),
    'checks' => $results,
    'passed' => $failed === [],
    'failed' => $failed,
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failed === [] ? 0 : 1);
