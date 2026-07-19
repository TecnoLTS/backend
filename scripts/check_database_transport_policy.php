<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $callback) use ($assert): void {
    $rejected = false;
    try {
        $callback();
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, 'La politica de transporte acepto una configuracion insegura.');
};

$ca = tempnam(sys_get_temp_dir(), 'pm-db-ca-');
if ($ca === false || file_put_contents($ca, "fixture-ca\n") === false) {
    throw new RuntimeException('No se pudo preparar CA fixture.');
}
chmod($ca, 0600);

try {
    $safe = [
        'APP_ENV' => 'production',
        'REQUIRE_HA' => 'true',
        'DB_POOL_MODE' => 'session',
        'DB_SSLMODE' => 'verify-full',
        'DB_SSLROOTCERT' => $ca,
    ];
    Database::assertProductionTransportSafety($safe);
    Database::assertProductionTransportSafety(['APP_ENV' => 'qa', 'REQUIRE_HA' => 'false']);

    foreach ([
        array_replace($safe, ['DB_POOL_MODE' => 'transaction']),
        array_replace($safe, ['DB_POOL_MODE' => 'direct']),
        array_replace($safe, ['DB_SSLMODE' => 'require']),
        array_replace($safe, ['DB_SSLMODE' => 'disable']),
        array_replace($safe, ['DB_SSLROOTCERT' => '/missing/ca.crt']),
        array_replace($safe, ['REQUIRE_HA' => 'maybe']),
    ] as $unsafe) {
        $rejects(static fn() => Database::assertProductionTransportSafety($unsafe));
    }

    $dsn = Database::buildDsnForConfig([
        'host' => 'paramascotasec-pgbouncer',
        'port' => 6432,
        'database' => 'ecommerce',
        'sslmode' => 'verify-full',
        'sslrootcert' => $ca,
    ]);
    $assert(str_contains($dsn, 'sslmode=verify-full'), 'DSN no activa verify-full.');
    $assert(str_contains($dsn, 'sslrootcert=' . $ca), 'DSN no propaga la CA.');
    $rejects(static fn() => Database::assertProductionTransportSafety($safe, [
        'sslmode' => 'require', 'sslrootcert' => $ca,
    ]));
    $rejects(static fn() => Database::buildDsnForConfig([
        'host' => 'pool', 'port' => 6432, 'database' => 'ecommerce', 'sslmode' => 'verify-full', 'sslrootcert' => 'relative.pem',
    ]));
} finally {
    @unlink($ca);
}

echo "Database transport policy: OK\n";
