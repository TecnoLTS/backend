<?php

declare(strict_types=1);

/*
 * Barre los pases de Google Wallet en estado sync-error y reintenta empujar
 * el balance a Google. Best-effort, pensado para cron o un worker de bucle
 * (mismo patron que process_billing_recovery.php).
 *
 * Uso: php scripts/sync_wallet_passes.php [--limit=25] [--min-age-seconds=120] [--tenant=xxx]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletFactory;
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

$options = getopt('', ['limit::', 'min-age-seconds::', 'tenant::']);
$limit = max(1, (int)($options['limit'] ?? 25));
$minAgeSeconds = max(0, (int)($options['min-age-seconds'] ?? 120));
$tenantFilter = trim((string)($options['tenant'] ?? ''));

const MAX_ATTEMPTS = 5;

try {
    $pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
} catch (\Throwable $e) {
    fwrite(STDERR, '[wallet-sync] sin conexion a base loyalty: ' . $e->getMessage() . PHP_EOL);
    exit(0);
}

$sql = "SELECT p.id AS pass_id, p.tenant_id, p.member_id, p.last_payload,
               m.account_id, m.account_name, m.wallet_platform,
               COALESCE(a.balance, 0) AS balance
        FROM loyalty_wallet_passes p
        JOIN loyalty_members m ON m.id = p.member_id AND m.tenant_id = p.tenant_id
        LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
        WHERE p.platform = 'google'
          AND p.status = 'sync-error'
          AND p.updated_at < NOW() - make_interval(secs => :age)" .
        ($tenantFilter !== '' ? ' AND p.tenant_id = :tenant_id' : '') .
        ' ORDER BY p.updated_at ASC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$params = ['age' => $minAgeSeconds];
if ($tenantFilter !== '') {
    $params['tenant_id'] = $tenantFilter;
}
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    fwrite(STDOUT, "[wallet-sync] sin pases pendientes de reintento\n");
    exit(0);
}

$servicesByTenant = [];
$settingsStmt = $pdo->prepare('SELECT settings FROM loyalty_program_settings WHERE tenant_id = :tenant_id LIMIT 1');
$programStmt = $pdo->prepare('SELECT * FROM loyalty_programs WHERE tenant_id = :tenant_id ORDER BY created_at ASC LIMIT 1');
$updateStmt = $pdo->prepare(
    'UPDATE loyalty_wallet_passes
     SET status = :status, external_object_id = :external_object_id, last_payload = :last_payload, updated_at = NOW()
     WHERE id = :id'
);

$ok = 0;
$failed = 0;
$abandoned = 0;

foreach ($rows as $row) {
    $tenantId = (string)$row['tenant_id'];

    if (!array_key_exists($tenantId, $servicesByTenant)) {
        $settingsStmt->execute(['tenant_id' => $tenantId]);
        $settingsRaw = $settingsStmt->fetchColumn();
        $settings = is_string($settingsRaw) ? (json_decode($settingsRaw, true) ?: []) : [];
        $programStmt->execute(['tenant_id' => $tenantId]);
        $program = $programStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        try {
            $servicesByTenant[$tenantId] = GoogleWalletFactory::make($tenantId, $settings, $program);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[wallet-sync] tenant {$tenantId}: no se pudo iniciar el servicio: {$e->getMessage()}\n");
            $servicesByTenant[$tenantId] = null;
        }
    }

    $service = $servicesByTenant[$tenantId];
    if ($service === null) {
        continue; // tenant sin configuracion: se deja el pase como esta
    }

    $payload = is_string($row['last_payload']) ? (json_decode($row['last_payload'], true) ?: []) : (array)$row['last_payload'];
    $attempts = (int)($payload['attempts'] ?? 0) + 1;
    $balance = (int)$row['balance'];

    try {
        $result = $service->pushPoints((string)$row['account_id'], (string)($row['account_name'] ?? $row['account_id']), $balance);
        $updateStmt->execute([
            'id' => (string)$row['pass_id'],
            'status' => 'synced',
            'external_object_id' => $result['objectId'],
            'last_payload' => json_encode(['points' => $balance, 'syncedAt' => date('c'), 'attempts' => $attempts, 'created' => $result['created']], JSON_UNESCAPED_UNICODE),
        ]);
        $ok++;
    } catch (\Throwable $e) {
        $status = $attempts >= MAX_ATTEMPTS ? 'sync-abandoned' : 'sync-error';
        $updateStmt->execute([
            'id' => (string)$row['pass_id'],
            'status' => $status,
            'external_object_id' => $service->objectId((string)$row['account_id']),
            'last_payload' => json_encode(['points' => $balance, 'attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 500), 'failedAt' => date('c')], JSON_UNESCAPED_UNICODE),
        ]);

        if ($status === 'sync-abandoned') {
            $riskStmt = $pdo->prepare(
                'INSERT INTO loyalty_risk_events (id, tenant_id, severity, event_type, status, member_id, reference, message, metadata)
                 VALUES (:id, :tenant_id, :severity, :event_type, :status, :member_id, :reference, :message, :metadata)'
            );
            $riskStmt->execute([
                'id' => 'risk_' . bin2hex(random_bytes(8)),
                'tenant_id' => $tenantId,
                'severity' => 'high',
                'event_type' => 'wallet_sync_abandoned',
                'status' => 'open',
                'member_id' => (string)$row['member_id'],
                'reference' => null,
                'message' => 'Sincronizacion con Google Wallet abandonada tras ' . MAX_ATTEMPTS . ' intentos.',
                'metadata' => json_encode(['balance' => $balance, 'error' => mb_substr($e->getMessage(), 0, 500)], JSON_UNESCAPED_UNICODE),
            ]);
            $abandoned++;
        } else {
            $failed++;
        }
    }
}

fwrite(STDOUT, sprintf("[wallet-sync] procesados=%d ok=%d fallidos=%d abandonados=%d\n", count($rows), $ok, $failed, $abandoned));
exit(0);
