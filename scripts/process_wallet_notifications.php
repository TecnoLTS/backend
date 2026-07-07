<?php

declare(strict_types=1);

/*
 * Drena las campañas de notificacion wallet pendientes: envia el mensaje a cada
 * destinatario via Google Wallet con throttling y reintentos. Pensado para cron
 * (mismo patron que sync_wallet_passes.php / process_billing_recovery.php).
 *
 * Uso: php scripts/process_wallet_notifications.php [--limit=50] [--tenant=xxx]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
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

$options = getopt('', ['limit::', 'tenant::']);
$limit = max(1, (int)($options['limit'] ?? 50));
$tenantFilter = trim((string)($options['tenant'] ?? '')) ?: null;
$throttleMs = max(0, (int)($_ENV['LOYALTY_WALLET_NOTIFY_THROTTLE_MS'] ?? 250));

try {
    $pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
} catch (\Throwable $e) {
    fwrite(STDERR, '[wallet-notify] sin conexion a base loyalty: ' . $e->getMessage() . PHP_EOL);
    exit(0);
}

$settingsStmt = $pdo->prepare('SELECT settings FROM loyalty_program_settings WHERE tenant_id = :tenant_id LIMIT 1');
$programStmt = $pdo->prepare('SELECT * FROM loyalty_programs WHERE tenant_id = :tenant_id ORDER BY created_at ASC LIMIT 1');

$resolver = static function (string $tenantId) use ($settingsStmt, $programStmt): ?WalletMessenger {
    $settingsStmt->execute(['tenant_id' => $tenantId]);
    $settingsRaw = $settingsStmt->fetchColumn();
    $settings = is_string($settingsRaw) ? (json_decode($settingsRaw, true) ?: []) : [];
    $programStmt->execute(['tenant_id' => $tenantId]);
    $program = $programStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    try {
        return GoogleWalletFactory::make($tenantId, $settings, $program);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[wallet-notify] tenant {$tenantId}: servicio no disponible: {$e->getMessage()}\n");
        return null;
    }
};

$processor = new WalletNotificationProcessor($pdo);
$tally = $processor->drainPending($limit, $tenantFilter, $resolver, $throttleMs);

fwrite(STDOUT, sprintf(
    "[wallet-notify] procesados=%d enviados=%d omitidos=%d fallidos=%d\n",
    $tally['processed'], $tally['sent'], $tally['skipped'], $tally['failed']
));
exit(0);
