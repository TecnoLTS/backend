<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Core\Database;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use App\Modules\LoyaltyRewards\Infrastructure\WalletNotificationProcessor;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\WalletMessenger;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

TenantContext::set(['id' => 'fidepuntos', 'slug' => 'fidepuntos', 'name' => 'Fidepuntos']);
$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$repository = new LoyaltyRepository($pdo); // dispara ensureSchema()

$checks = [];
$assert = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    fwrite($ok ? STDOUT : STDERR, sprintf("[%s] %s\n", $ok ? 'OK' : 'FAIL', $name));
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT to_regclass(:t) IS NOT NULL');
    $stmt->execute(['t' => $table]);
    return (bool)$stmt->fetchColumn();
};

$assert('tabla loyalty_wallet_campaigns existe', $tableExists($pdo, 'loyalty_wallet_campaigns'));
$assert('tabla loyalty_wallet_campaign_recipients existe', $tableExists($pdo, 'loyalty_wallet_campaign_recipients'));

$preview = $repository->previewNotificationAudience(['audience_type' => 'all']);
$assert('preview all devuelve entero >= 0', isset($preview['recipients']) && is_int($preview['recipients']) && $preview['recipients'] >= 0);

$segment = $repository->previewNotificationAudience([
    'audience_type' => 'segment',
    'tier' => 'Oro',
    'minBalance' => 100,
]);
$assert('preview segment devuelve entero >= 0', isset($segment['recipients']) && is_int($segment['recipients']) && $segment['recipients'] >= 0);
$assert('segment <= all', $segment['recipients'] <= $preview['recipients']);

$anyMember = $repository->customersPage(['limit' => 1, 'offset' => 0, 'count' => '0', 'wallet' => 'all', 'tier' => 'all', 'status' => 'all', 'sort' => 'recent'])['items'][0] ?? null;
$assert('hay al menos un socio de demo', $anyMember !== null);

$campaign = null;
if ($anyMember !== null) {
    $campaign = $repository->createNotificationCampaign([
        'audience_type' => 'individual',
        'memberId' => $anyMember['id'],
        'title' => 'Prueba',
        'body' => 'Mensaje de prueba de campaña individual',
    ], 'exercise-user');

    $assert('campaña individual creada con id', !empty($campaign['id']));
    $assert('campaña individual total_recipients=1', (int)($campaign['total_recipients'] ?? 0) === 1);
    // Entorno de prueba desconectado de Google Wallet: no se puede garantizar un
    // estado terminal determinista (ver accommodation en el brief de A3/A4).
    $assert(
        'campaña individual en un estado esperado',
        in_array($campaign['status'] ?? '', ['completed', 'completed_with_errors', 'processing', 'pending'], true)
    );
}

if (!empty($campaign['id'])) {
    $fetched = $repository->getNotificationCampaign($campaign['id']);
    $assert('getNotificationCampaign devuelve la misma campaña', ($fetched['id'] ?? null) === $campaign['id']);
    $assert('audience_filter viene como array', is_array($fetched['audience_filter'] ?? null));

    $recipientCountStmt = $pdo->prepare('SELECT COUNT(*) FROM loyalty_wallet_campaign_recipients WHERE campaign_id = :id');
    $recipientCountStmt->execute(['id' => $campaign['id']]);
    $assert('existe un destinatario para la campaña', (int)$recipientCountStmt->fetchColumn() === 1);

    $list = $repository->listNotificationCampaigns(['limit' => 10]);
    $assert('listNotificationCampaigns trae items', isset($list['items']) && count($list['items']) >= 1);
    $assert('la campaña creada aparece en la lista', (bool)array_filter($list['items'], static fn($c) => ($c['id'] ?? '') === $campaign['id']));
}

// Doble minimo: implementa el puerto WalletMessenger sin depender de las
// credenciales reales de Google (evita llamadas HTTP en el ejercicio).
$fakeService = new class implements WalletMessenger {
    public function addMessage(string $accountId, string $header, string $body): array {
        return ['objectId' => 'obj_' . $accountId, 'messageId' => 'msg_fake'];
    }
};

$previewAll = $repository->previewNotificationAudience(['audience_type' => 'all'])['recipients'];
if ($previewAll >= 1) {
    $massive = $repository->createNotificationCampaign([
        'audience_type' => 'all',
        'title' => 'Masivo',
        'body' => 'Promo de prueba masiva',
    ], 'exercise-user');
    $assert('campaña masiva queda pending', ($massive['status'] ?? '') === 'pending');
    $assert('campaña masiva total = preview all', (int)$massive['total_recipients'] === $previewAll);

    $processor = new WalletNotificationProcessor($pdo);
    $tally = $processor->drainCampaign($massive['id'], $fakeService);
    $assert('drenado proceso todos', $tally['processed'] === $previewAll);
    $assert('drenado marco enviados', $tally['sent'] === $previewAll);

    $after = $repository->getNotificationCampaign($massive['id']);
    $assert('campaña masiva quedo completed', ($after['status'] ?? '') === 'completed');
    $assert('sent_count coincide', (int)$after['sent_count'] === $previewAll);
}

// A7: drainPending drena cross-campaña/tenant resolviendo el servicio por tenant.
// Reusa $fakeService (declarado arriba) y $processor si A5 lo dejo definido; si no,
// se recrea aqui (mismo WalletNotificationProcessor, mismo $pdo).
$processor = $processor ?? new WalletNotificationProcessor($pdo);

// Primero se drena con el doble fake cualquier residuo 'pending' de campañas previas
// (p.ej. el envio inline de la campaña individual arriba, que en este entorno sin
// salida de red a Google queda 'pending'), para que el conteo de abajo sea exacto.
$processor->drainPending(1000, 'fidepuntos', static fn(string $t): WalletMessenger => $fakeService);

$previewAllForDrain = $repository->previewNotificationAudience(['audience_type' => 'all'])['recipients'];
if ($previewAllForDrain >= 1) {
    $worker = $repository->createNotificationCampaign([
        'audience_type' => 'all',
        'title' => 'Worker',
        'body' => 'via drainPending',
    ], 'exercise-user');
    $assert('campaña worker queda pending', ($worker['status'] ?? '') === 'pending');
    $assert('campaña worker total = preview all', (int)$worker['total_recipients'] === $previewAllForDrain);

    $drainTally = $processor->drainPending(50, 'fidepuntos', static fn(string $t): WalletMessenger => $fakeService);
    $assert('drainPending envio todos los pendientes', $drainTally['sent'] === $previewAllForDrain);

    $workerAfter = $repository->getNotificationCampaign($worker['id']);
    $assert('campaña worker quedo completed', ($workerAfter['status'] ?? '') === 'completed');
    $assert('sent_count de worker coincide', (int)$workerAfter['sent_count'] === $previewAllForDrain);
}

$failed = array_keys(array_filter($checks, static fn($v) => !$v));
exit($failed === [] ? 0 : 1);
