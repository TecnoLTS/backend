<?php

declare(strict_types=1);

/*
 * Drena las campañas de notificacion wallet pendientes: envia el mensaje a cada
 * destinatario via Google Wallet con throttling y reintentos. Pensado para cron
 * (mismo patron que sync_wallet_passes.php / process_billing_recovery.php).
 *
 * Uso: php scripts/process_wallet_notifications.php [--limit=50] [--max-seconds=240] [--tenant=xxx]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Infrastructure\Workers\WorkerCycleResult;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletFactory;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\WalletMessenger;
use App\Modules\LoyaltyRewards\Infrastructure\WalletNotificationProcessor;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
foreach (getenv() ?: [] as $key => $value) {
    if (is_string($key) && !array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}
$_ENV['DB_CONNECTION_ROLE'] = strtolower(trim((string)($_ENV['DB_CONNECTION_ROLE'] ?? 'worker')));
if ($_ENV['DB_CONNECTION_ROLE'] !== 'worker') {
    fwrite(STDERR, "[wallet-notify] DB_CONNECTION_ROLE debe ser worker\n");
    exit(1);
}

$options = getopt('', ['limit::', 'max-seconds::', 'tenant::']);
$limit = max(1, min(10000, (int)($options['limit'] ?? 50)));
$maxSeconds = max(15, min(540, (int)($options['max-seconds']
    ?? $_ENV['LOYALTY_WALLET_NOTIFY_MAX_SECONDS']
    ?? 240)));
$deadlineMonotonic = (hrtime(true) / 1000000000) + $maxSeconds;
$tenantFilter = trim((string)($options['tenant'] ?? '')) ?: null;
$throttleMs = max(0, (int)($_ENV['LOYALTY_WALLET_NOTIFY_THROTTLE_MS'] ?? 250));

try {
    $pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
} catch (\Throwable $e) {
    fwrite(STDERR, '[wallet-notify] sin conexion a base loyalty: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$settingsStmt = $pdo->prepare('SELECT settings FROM loyalty_program_settings WHERE tenant_id = :tenant_id LIMIT 1');
$programStmt = $pdo->prepare(
    'SELECT id, tenant_id, name, status, points_per_currency, currency_code,
            wallet_issuer_name, wallet_program_name, brand_color, logo_url,
            metadata, created_at, updated_at
       FROM loyalty_programs
      WHERE tenant_id = :tenant_id
      ORDER BY created_at ASC
      LIMIT 1'
);

$resolverAttempts = 0;
$resolverFailures = 0;
$resolver = static function (string $tenantId) use ($settingsStmt, $programStmt, &$resolverAttempts, &$resolverFailures): ?WalletMessenger {
    $resolverAttempts++;
    $settingsStmt->execute(['tenant_id' => $tenantId]);
    $settingsRaw = $settingsStmt->fetchColumn();
    $settings = is_string($settingsRaw) ? (json_decode($settingsRaw, true) ?: []) : [];
    $programStmt->execute(['tenant_id' => $tenantId]);
    $program = $programStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    try {
        return GoogleWalletFactory::make($tenantId, $settings, $program);
    } catch (\Throwable $e) {
        $resolverFailures++;
        fwrite(STDERR, "[wallet-notify] tenant {$tenantId}: servicio no disponible: {$e->getMessage()}\n");
        return null;
    }
};

$processor = new WalletNotificationProcessor($pdo);
$tally = $processor->drainPending($limit, $tenantFilter, $resolver, $throttleMs, $deadlineMonotonic);

$cycle = new WorkerCycleResult('wallet-notifications', [
    'attempted' => (int)$tally['processed'] + $resolverAttempts,
    'succeeded' => (int)$tally['sent'] + max(0, $resolverAttempts - $resolverFailures),
    'skipped' => (int)$tally['skipped'],
    'failed' => (int)$tally['failed'] + $resolverFailures,
    'unknown' => (int)($tally['delivery_unknown'] ?? 0),
], [
    'resolver_attempts' => $resolverAttempts,
    'resolver_failures' => $resolverFailures,
    'recipient_budget' => $limit,
    'deadline_seconds' => $maxSeconds,
    'budget_consumed' => (int)$tally['processed'],
]);
$cycle->emit();
exit($cycle->exitCode());
