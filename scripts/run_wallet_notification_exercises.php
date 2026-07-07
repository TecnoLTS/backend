<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Core\Database;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
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

$failed = array_keys(array_filter($checks, static fn($v) => !$v));
exit($failed === [] ? 0 : 1);
