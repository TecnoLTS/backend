<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Modules\Billing\Infrastructure\NativeBillingGateway;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$tenantId = strtolower(trim((string)($_ENV['DEFAULT_TENANT'] ?? $_ENV['PUBLIC_TENANT_SLUG'] ?? getenv('DEFAULT_TENANT') ?: '')));
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $tenantId)) {
    fwrite(STDERR, "[billing-secret-runtime] failed: DEFAULT_TENANT is not a canonical tenant slug.\n");
    exit(1);
}
TenantContext::set([
    'id' => $tenantId,
    'slug' => $tenantId,
    'name' => 'Billing runtime readiness',
]);

try {
    $gateway = new NativeBillingGateway();
    $gateway->assertReady();
    $health = $gateway->health();
    fwrite(STDOUT, sprintf(
        "Billing secret runtime readiness: OK mode=%s\n",
        (string)($health['secret_storage'] ?? 'unknown')
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, '[billing-secret-runtime] failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    TenantContext::clear();
}
