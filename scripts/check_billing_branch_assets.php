<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use App\Modules\Billing\Domain\BillingDomain;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;
use Dotenv\Dotenv;

/**
 * Runtime-only audit of the sensitive assets required by enabled Billing
 * branches. Secret values and certificate contents must never reach stdout,
 * stderr, argv or the host running this script.
 */

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, json_encode([
        'event' => 'billing_branch_asset_audit_failed_closed',
        'error_type' => (new ReflectionClass($exception))->getShortName(),
        'error_code' => (int)$exception->getCode(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
});

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/check_billing_branch_assets.php --mode=qa|production --tenant=slug\n");
    exit(2);
}

$options = getopt('', ['mode:', 'tenant:']);
$mode = strtolower(trim((string)($options['mode'] ?? '')));
$tenantId = trim((string)($options['tenant'] ?? ''));
if (!in_array($mode, ['qa', 'production'], true)
    || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $tenantId) !== 1
) {
    fwrite(STDERR, "Usage: php scripts/check_billing_branch_assets.php --mode=qa|production --tenant=slug\n");
    exit(2);
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
foreach (getenv() ?: [] as $key => $value) {
    if (is_string($key) && !array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$runtimeEnvironmentValue = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (!is_string($runtimeEnvironmentValue) || trim($runtimeEnvironmentValue) === '') {
    $runtimeEnvironmentValue = $_ENV['ENTORNO_MODE'] ?? getenv('ENTORNO_MODE');
}
$runtimeEnvironment = is_string($runtimeEnvironmentValue)
    ? strtolower(trim($runtimeEnvironmentValue))
    : '';
$runtimeMode = match ($runtimeEnvironment) {
    'qa', 'test', 'testing' => 'qa',
    'production', 'prod' => 'production',
    default => '',
};
if ($runtimeMode === '' || $runtimeMode !== $mode) {
    throw new RuntimeException('The requested Billing audit mode does not match the running backend environment.');
}

$connectionRole = strtolower(trim((string)($_ENV['DB_CONNECTION_ROLE'] ?? getenv('DB_CONNECTION_ROLE') ?: 'app')));
if ($connectionRole !== 'app') {
    throw new RuntimeException('Billing branch assets must be audited with the backend API database identity.');
}

TenantContext::set([
    'id' => $tenantId,
    'slug' => $tenantId,
]);

$connection = Database::getModuleInstance(BillingDomain::KEY);
$enabledColumn = $mode === 'production' ? 'api_produccion' : 'api_test';
$statement = $connection->prepare(sprintf(
    'SELECT id, tenant_id, certificate_path, certificate_password,
            mail_enabled, mail_host, mail_port
       FROM public.client_branches
      WHERE tenant_id = :tenant_id
        AND is_active = TRUE
        AND %s = TRUE
      ORDER BY id',
    $enabledColumn
));
$statement->execute(['tenant_id' => $tenantId]);
$rows = $statement->fetchAll();
if (!is_array($rows)) {
    throw new RuntimeException('Billing branch asset query returned an invalid result.');
}

$cipher = BillingSecretCipherFactory::fromEnvironment();
$artifacts = new BillingArtifactStorage(null, $tenantId);
$failures = 0;

foreach ($rows as $row) {
    if (!is_array($row)) {
        throw new RuntimeException('Billing branch asset query returned an invalid row.');
    }

    $branchId = (int)($row['id'] ?? 0);
    $rowTenantId = trim((string)($row['tenant_id'] ?? ''));
    if ($branchId <= 0 || !hash_equals($tenantId, $rowTenantId)) {
        throw new RuntimeException('Billing branch tenant context is invalid.');
    }

    $errorCodes = [];
    $certificateReference = trim((string)($row['certificate_path'] ?? ''));
    $storedPassword = $row['certificate_password'] ?? null;
    $password = '';
    $certificateContents = '';
    $certificates = [];

    if ($certificateReference === '') {
        $errorCodes[] = 'certificate_reference_missing';
    }
    if (!is_string($storedPassword) || $storedPassword === '') {
        $errorCodes[] = 'certificate_secret_missing';
    }

    if ($errorCodes === []) {
        try {
            $password = $cipher->decryptStored(
                $storedPassword,
                $rowTenantId,
                $branchId,
                'certificate_password'
            );
        } catch (Throwable) {
            $errorCodes[] = 'certificate_secret_authentication_failed';
        }
    }

    if ($errorCodes === []) {
        try {
            $certificateContents = $artifacts->read($certificateReference);
        } catch (Throwable) {
            $errorCodes[] = 'certificate_unreadable';
        }
    }

    if ($errorCodes === []) {
        $parsed = @openssl_pkcs12_read($certificateContents, $certificates, $password);
        $leafCertificate = $certificates['cert'] ?? null;
        $privateKey = $certificates['pkey'] ?? null;
        if ($parsed !== true
            || !is_string($leafCertificate)
            || trim($leafCertificate) === ''
            || !is_string($privateKey)
            || trim($privateKey) === ''
            || !is_array(@openssl_x509_parse($leafCertificate))
        ) {
            $errorCodes[] = 'certificate_pkcs12_invalid';
        }
    }

    $mailEnabled = filter_var($row['mail_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($mailEnabled) {
        $mailHost = trim((string)($row['mail_host'] ?? ''));
        $mailPort = (int)($row['mail_port'] ?? 0);
        if ($mailHost === '' || $mailPort < 1 || $mailPort > 65535) {
            $errorCodes[] = 'mail_endpoint_invalid';
        }
    }

    $storedPassword = is_string($storedPassword)
        ? str_repeat("\0", strlen($storedPassword))
        : null;
    $password = str_repeat("\0", strlen($password));
    $certificateContents = str_repeat("\0", strlen($certificateContents));
    $certificates = [];

    if ($errorCodes !== []) {
        $failures++;
    }
    fwrite($errorCodes === [] ? STDOUT : STDERR, json_encode([
        'event' => 'billing_branch_asset_verified',
        'branch_id' => $branchId,
        'status' => $errorCodes === [] ? 'ok' : 'failed',
        'certificate' => $errorCodes === [] ? 'pkcs12_with_private_key' : 'unverified',
        'mail' => $mailEnabled ? (in_array('mail_endpoint_invalid', $errorCodes, true) ? 'invalid' : 'configured') : 'disabled',
        'error_codes' => $errorCodes,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

fwrite($failures === 0 ? STDOUT : STDERR, json_encode([
    'event' => 'billing_branch_asset_audit_complete',
    'mode' => $mode,
    'branches_checked' => count($rows),
    'failures' => $failures,
    'secret_transport' => 'backend_runtime_only',
], JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit($failures === 0 ? 0 : 1);
