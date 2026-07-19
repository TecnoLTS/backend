<?php

namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static $instances = [];
    private $connection;

    private function __construct(array $config) {
        $user = $config['username'];
        $pass = $config['password'];

        self::assertProductionTransportSafety(null, $config);
        $dsn = self::buildDsnForConfig($config);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // El aislamiento RLS usa un GUC de sesion. No permitir que PDO
            // conserve o comparta una sesion entre requests/modulos PHP-FPM.
            // Produccion amortiza conexiones mediante PgBouncer session.
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    private static function resolveConfig(?string $moduleKey = null): array {
        return ConnectionRegistry::resolveDatabaseConfig($moduleKey);
    }

    public static function getInstance(?string $moduleKey = null) {
        $config = self::resolveConfig($moduleKey);
        return self::connectionForConfig($config);
    }

    /**
     * Opens one narrowly named control-plane capability before request tenant
     * resolution. It does not grant database privileges: the same runtime role
     * can execute only the audited SECURITY DEFINER routines already granted to
     * it, while direct tenant-table access remains denied by ACL/RLS.
     */
    public static function getModuleCapabilityInstance(string $moduleKey, string $capability) {
        $normalizedModule = strtolower(str_replace('_', '-', trim($moduleKey)));
        $normalizedCapability = strtolower(str_replace('_', '-', trim($capability)));
        if ($normalizedModule !== 'identity-platform' || $normalizedCapability !== 'tenant-runtime-registry') {
            throw new \InvalidArgumentException('Database module capability is not allowlisted.');
        }

        $config = self::resolveConfig($normalizedModule);
        $config['connection_role'] = 'platform_capability';
        $config['capability'] = $normalizedCapability;

        return self::connectionForConfig($config);
    }

    private static function connectionForConfig(array $config) {
        $key = implode('|', [
            $config['host'],
            $config['port'],
            $config['database'],
            $config['username'],
            $config['sslmode'] ?? 'prefer',
            $config['sslrootcert'] ?? '',
            $config['module'] ?? 'primary',
            $config['connection_role'] ?? 'app',
            $config['capability'] ?? '',
        ]);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config);
        }
        self::$instances[$key]->synchronizeTenantContext((string)($config['connection_role'] ?? 'app'));
        return self::$instances[$key]->connection;
    }

    public static function getModuleInstance(string $moduleKey) {
        return self::getInstance($moduleKey);
    }

    private function synchronizeTenantContext(string $connectionRole): void
    {
        $mode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
        $tenantId = $connectionRole === 'app' ? trim((string)(TenantContext::id() ?? TenantContext::slug() ?? '')) : '';
        if ($mode === 'enforce' && $connectionRole === 'app' && $tenantId === '') {
            throw new \RuntimeException('RLS activo: la conexion PDO de aplicacion requiere TenantContext resuelto.');
        }

        self::assertRlsPoolSafetyConfig($mode);

        // set_config reemplaza atomicamente el valor previo de la sesion. Una
        // sola expresion evita depender del orden de evaluacion del target list
        // de SELECT y deja siempre un unico valor final comprobable.
        $statement = $this->connection->prepare(
            "SELECT set_config('app.tenant_id', :tenant_id, false)"
        );
        $statement->execute(['tenant_id' => $tenantId]);
    }

    public static function assertRlsPoolSafetyConfig(?string $rlsMode = null): void
    {
        $mode = strtolower(trim((string)($rlsMode
            ?? $_ENV['TENANT_RLS_MODE']
            ?? getenv('TENANT_RLS_MODE')
            ?: 'off')));
        if ($mode !== 'enforce') {
            return;
        }

        $poolMode = strtolower(trim((string)(
            $_ENV['DB_POOL_MODE']
            ?? getenv('DB_POOL_MODE')
            ?: 'direct'
        )));
        if (!in_array($poolMode, ['direct', 'session'], true)) {
            throw new \RuntimeException(
                'RLS enforce solo admite DB_POOL_MODE=direct|session; transaction/statement pooling puede mezclar el contexto tenant entre consultas.'
            );
        }
    }

    /** @param array<string, mixed> $config */
    public static function buildDsnForConfig(array $config): string
    {
        $host = trim((string)($config['host'] ?? ''));
        $port = (int)($config['port'] ?? 0);
        $database = trim((string)($config['database'] ?? ''));
        $sslMode = strtolower(trim((string)($config['sslmode'] ?? 'prefer')));
        $sslRootCert = trim((string)($config['sslrootcert'] ?? ''));
        if ($host === '' || $database === '' || $port < 1 || $port > 65535) {
            throw new \RuntimeException('Configuracion PostgreSQL host/port/database invalida.');
        }
        if (!in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new \RuntimeException('DB_SSLMODE no es valido.');
        }
        if (in_array($sslMode, ['verify-ca', 'verify-full'], true)
            && ($sslRootCert === '' || $sslRootCert[0] !== '/' || str_contains($sslRootCert, ';'))) {
            throw new \RuntimeException('DB_SSLROOTCERT absoluto es obligatorio con TLS verificado.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode);
        if ($sslRootCert !== '') {
            $dsn .= ';sslrootcert=' . $sslRootCert;
        }

        return $dsn;
    }

    /**
     * @param array<string, mixed>|null $environment
     * @param array<string, mixed>|null $connectionConfig
     */
    public static function assertProductionTransportSafety(?array $environment = null, ?array $connectionConfig = null): void
    {
        $value = static function (string $name, string $default = '') use ($environment): string {
            if (is_array($environment) && array_key_exists($name, $environment)) {
                return trim((string)$environment[$name]);
            }
            $resolved = $_ENV[$name] ?? getenv($name);
            return $resolved === false || $resolved === null ? $default : trim((string)$resolved);
        };
        $appEnv = strtolower($value('APP_ENV', $value('ENTORNO_MODE', 'qa')));
        $rawRequireHa = strtolower($value('REQUIRE_HA', 'false'));
        $requireHa = filter_var($rawRequireHa, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($requireHa === null) {
            throw new \RuntimeException('REQUIRE_HA debe ser booleano.');
        }
        if (!in_array($appEnv, ['production', 'prod'], true) || !$requireHa) {
            return;
        }

        $poolMode = strtolower($value('DB_POOL_MODE', 'direct'));
        $sslMode = strtolower(trim((string)($connectionConfig['sslmode'] ?? $value('DB_SSLMODE', 'prefer'))));
        $sslRootCert = trim((string)($connectionConfig['sslrootcert'] ?? $value('DB_SSLROOTCERT')));
        if ($poolMode !== 'session') {
            throw new \RuntimeException('Production HA exige PgBouncer con DB_POOL_MODE=session.');
        }
        if ($sslMode !== 'verify-full') {
            throw new \RuntimeException('Production HA exige DB_SSLMODE=verify-full hacia PgBouncer.');
        }
        if ($sslRootCert === '' || $sslRootCert[0] !== '/' || !is_file($sslRootCert) || !is_readable($sslRootCert)) {
            throw new \RuntimeException('Production HA exige un DB_SSLROOTCERT absoluto y legible.');
        }
    }
}
