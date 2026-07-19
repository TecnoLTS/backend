<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Commerce\Infrastructure\Security\CommerceBillingCredentialRegistry;
use Dotenv\Dotenv;

set_exception_handler(static function (Throwable $exception): never {
    // Never print exception messages here: an unexpected parser/runtime error
    // must not turn a credential or fragment of the registry into log output.
    fwrite(STDERR, sprintf(
        "Commerce Billing credential operation failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
});

function usage(): never
{
    fwrite(STDERR, <<<'TEXT'
Usage:
  php scripts/manage_commerce_billing_credentials.php validate [--file=/absolute/path]
  php scripts/manage_commerce_billing_credentials.php upsert --tenant=slug --hosts=host[,host] --credential-file=/absolute/0600/file [--file=/absolute/path]
  php scripts/manage_commerce_billing_credentials.php migrate-legacy-env [--env-file=/absolute/path] [--tenant=slug] [--hosts=host[,host]] [--file=/absolute/path]
  php scripts/manage_commerce_billing_credentials.php remove --tenant=slug [--file=/absolute/path]

Raw API keys are intentionally rejected in argv and are never printed.
TEXT
    );
    exit(2);
}

/** @return array<string,string> */
function parseOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            usage();
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, ['file', 'env-file', 'tenant', 'hosts', 'credential-file'], true)
            || isset($options[$name])
            || trim($value) === '') {
            usage();
        }
        $options[$name] = trim($value);
    }
    return $options;
}

function absolutePath(string $path, string $default): string
{
    $path = trim($path);
    if ($path === '') {
        $path = $default;
    }
    if ($path[0] !== '/') {
        $path = dirname(__DIR__) . '/' . ltrim($path, '/');
    }
    return $path;
}

/** @return list<string> */
function hostList(string $raw): array
{
    $hosts = array_values(array_filter(array_map(
        static fn(string $host): string => strtolower(rtrim(trim($host), '.')),
        explode(',', $raw)
    ), static fn(string $host): bool => $host !== ''));
    if ($hosts === []) {
        throw new InvalidArgumentException('At least one tenant host is required.');
    }
    return $hosts;
}

function readProtectedCredentialFile(string $path): string
{
    if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Credential input must be a readable absolute regular file.');
    }
    $permissions = fileperms($path);
    if (!is_int($permissions) || (($permissions & 0077) !== 0)) {
        throw new RuntimeException('Credential input file must be owner-only (0600 or stricter).');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 24 || $size > 2048) {
        throw new RuntimeException('Credential input file size is invalid.');
    }
    $value = trim((string)file_get_contents($path));
    if (strlen($value) < 24 || strlen($value) > 512 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
        throw new RuntimeException('Credential input does not meet the safety policy.');
    }
    return $value;
}

/** @return array{version:int,credentials:list<array{tenant_id:string,allowed_hosts:list<string>,api_key:string}>} */
function existingDocument(string $path): array
{
    if (!file_exists($path)) {
        return ['version' => 1, 'credentials' => []];
    }
    return CommerceBillingCredentialRegistry::loadFile($path)['document'];
}

/** @param array{version:int,credentials:list<array{tenant_id:string,allowed_hosts:list<string>,api_key:string}>} $document */
function writeRegistry(string $path, array $document): void
{
    if (is_link($path)) {
        throw new RuntimeException('Credential registry target must not be a symlink.');
    }
    $document = CommerceBillingCredentialRegistry::normalizedDocument($document);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Credential registry directory could not be created.');
    }
    if (is_link($directory) || !chmod($directory, 0700)) {
        throw new RuntimeException('Credential registry directory could not be restricted.');
    }
    $temporary = tempnam($directory, '.commerce-billing-credentials-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Credential registry temporary file could not be created.');
    }
    try {
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('Credential registry temporary permissions could not be set.');
        }
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Credential registry temporary file could not be opened.');
        }
        try {
            if (!flock($handle, LOCK_EX)
                || fwrite($handle, $encoded) !== strlen($encoded)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Credential registry atomic write was incomplete.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Credential registry atomic replacement failed.');
        }
        // Docker Compose implements file-backed secrets as read-only bind
        // mounts and cannot remap uid/gid. The host directory remains 0700;
        // 0444 lets the isolated uid 82 read only the one mounted file.
        if (!chmod($path, 0444)) {
            throw new RuntimeException('Credential registry runtime permissions could not be set.');
        }
        CommerceBillingCredentialRegistry::loadFile($path);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        $encoded = '';
    }
}

$command = $argv[1] ?? '';
if (!in_array($command, ['validate', 'upsert', 'migrate-legacy-env', 'remove'], true)) {
    usage();
}
$options = parseOptions(array_slice($argv, 2));
$path = absolutePath(
    $options['file'] ?? '',
    dirname(__DIR__) . '/entorno/.secrets/commerce-billing-credentials.json'
);

if ($command === 'validate') {
    if (array_diff(array_keys($options), ['file']) !== []) {
        usage();
    }
    $document = CommerceBillingCredentialRegistry::loadFile($path)['document'];
    $hostCount = array_sum(array_map(static fn(array $entry): int => count($entry['allowed_hosts']), $document['credentials']));
    printf(
        "Commerce Billing credential registry: OK tenants=%d hosts=%d (credential values hidden)\n",
        count($document['credentials']),
        $hostCount
    );
    exit(0);
}

$tenantId = strtolower(trim((string)($options['tenant'] ?? '')));
$hosts = isset($options['hosts']) ? hostList($options['hosts']) : [];
$apiKey = '';
if ($command === 'upsert') {
    if ($tenantId === '' || $hosts === [] || !isset($options['credential-file'])
        || array_diff(array_keys($options), ['file', 'tenant', 'hosts', 'credential-file']) !== []) {
        usage();
    }
    $apiKey = readProtectedCredentialFile($options['credential-file']);
} elseif ($command === 'migrate-legacy-env') {
    if (array_diff(array_keys($options), ['file', 'env-file', 'tenant', 'hosts']) !== []) {
        usage();
    }
    $envPath = absolutePath($options['env-file'] ?? '', dirname(__DIR__) . '/entorno/.env');
    if (is_link($envPath) || !is_file($envPath) || !is_readable($envPath)) {
        throw new RuntimeException('Legacy environment file is unavailable.');
    }
    $parsed = Dotenv::parse((string)file_get_contents($envPath));
    $apiKey = trim((string)($parsed['BILLING_API_KEY'] ?? ''));
    if ($tenantId === '') {
        $tenantId = strtolower(trim((string)($parsed['PUBLIC_TENANT_SLUG'] ?? $parsed['DEFAULT_TENANT'] ?? '')));
    }
    if ($hosts === []) {
        $hosts = hostList(implode(',', array_filter([
            (string)($parsed['PRIMARY_SITE_DOMAIN'] ?? parse_url((string)($parsed['APP_URL'] ?? ''), PHP_URL_HOST)),
            (string)($parsed['PRIMARY_SITE_ALIASES'] ?? ''),
        ])));
    }
    if (strlen($apiKey) < 24 || strlen($apiKey) > 512 || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
        throw new RuntimeException('Legacy Billing API credential is unavailable or invalid.');
    }
} elseif ($command === 'remove') {
    if ($tenantId === '' || array_diff(array_keys($options), ['file', 'tenant']) !== []) {
        usage();
    }
}

$document = existingDocument($path);
$entries = [];
foreach ($document['credentials'] as $entry) {
    if (!hash_equals((string)$entry['tenant_id'], $tenantId)) {
        $entries[] = $entry;
    }
}
if ($command !== 'remove') {
    $entries[] = ['tenant_id' => $tenantId, 'allowed_hosts' => $hosts, 'api_key' => $apiKey];
}
if ($entries === []) {
    throw new RuntimeException('Credential registry cannot remove its last tenant.');
}
writeRegistry($path, ['version' => 1, 'credentials' => $entries]);
$apiKey = '';
printf(
    "Commerce Billing credential registry %s: OK tenant=%s tenants=%d (credential values hidden)\n",
    $command,
    $tenantId,
    count($entries)
);
