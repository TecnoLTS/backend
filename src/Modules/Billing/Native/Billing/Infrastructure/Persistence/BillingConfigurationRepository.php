<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use RuntimeException;

class BillingConfigurationRepository
{
    private const CERTIFICATE_UPLOAD_DIR = '/var/www/html/storage/billing/certs';
    private const CERTIFICATE_MAX_BYTES = 10485760;
    private const MIN_RETRY_DELAY_SECONDS = 3600;

    public function __construct(private readonly PDO $connection) {}

    public function getConfiguration(array $clientContext, array $baseConfig): array
    {
        $row = $this->requireBranchRow($clientContext);
        $retrySettings = $this->retrySettingsByEnvironment();

        return $this->buildConfigurationPayload($row, $retrySettings, $baseConfig);
    }

    public function updateConfiguration(array $clientContext, array $payload, array $baseConfig): array
    {
        $current = $this->requireBranchRow($clientContext);
        $client = is_array($payload['client'] ?? null) ? $payload['client'] : [];
        $branch = is_array($payload['branch'] ?? null) ? $payload['branch'] : [];
        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];
        $environments = is_array($payload['environments'] ?? null) ? $payload['environments'] : [];
        $retries = is_array($payload['retries'] ?? null) ? $payload['retries'] : [];

        $clientId = (int) $current['client_id'];
        $branchId = (int) $current['branch_id'];
        $ruc = $this->normalizeRuc($client['ruc'] ?? $credentials['ruc'] ?? $current['ruc']);
        $businessName = $this->requiredString($client['business_name'] ?? $credentials['business_name'] ?? $current['business_name'], 'Razon social requerida.');
        $tradeName = $this->optionalString($client['trade_name'] ?? $credentials['trade_name'] ?? $current['trade_name']);
        $email = $this->optionalString($client['email'] ?? $credentials['email'] ?? $current['email']);
        $clientAddress = $this->requiredString($client['address'] ?? $credentials['address'] ?? $current['client_address'], 'Direccion matriz requerida.');
        $branchCode = $this->normalizeThreeDigitCode($branch['code'] ?? $credentials['branch_code'] ?? $current['branch_code'], 'Codigo de establecimiento');
        $emissionPoint = $this->normalizeThreeDigitCode($branch['emission_point'] ?? $credentials['emission_point'] ?? $current['emission_point'], 'Punto de emision');
        $branchName = $this->optionalString($branch['name'] ?? $branch['branch_name'] ?? $credentials['branch_name'] ?? $current['branch_name']);
        $branchAddress = $this->requiredString($branch['address'] ?? $credentials['branch_address'] ?? $current['branch_address'], 'Direccion de sucursal requerida.');
        $apiTest = $this->payloadBool($environments['test']['enabled'] ?? null, $current['api_test']);
        $apiProduction = $this->payloadBool($environments['production']['enabled'] ?? null, $current['api_produccion']);
        $retryTest = $this->payloadBool($retries['test']['enabled'] ?? null, $current['reintentos_test']);
        $retryProduction = $this->payloadBool($retries['production']['enabled'] ?? null, $current['reintentos_produccion']);
        $certificatePassword = $this->optionalString($credentials['certificate_password'] ?? $payload['certificate_password'] ?? null);

        $this->assertUniqueClientRuc($ruc, $clientId);
        $this->assertUniqueBranchCode($clientId, $branchCode, $emissionPoint, $branchId);
        if ($certificatePassword !== null && $certificatePassword !== '') {
            $this->validateStoredCertificatePassword($current['certificate_path'] ?? null, $certificatePassword);
        }

        $retrySettings = $this->retrySettingsByEnvironment();
        $testRetry = $this->normalizeRetryPayload($retries['test'] ?? null, $retrySettings['pruebas'] ?? [], 'pruebas');
        $productionRetry = $this->normalizeRetryPayload($retries['production'] ?? null, $retrySettings['produccion'] ?? [], 'produccion');

        $this->connection->beginTransaction();
        try {
            $this->updateClient($clientId, [
                'ruc' => $ruc,
                'business_name' => $businessName,
                'trade_name' => $tradeName,
                'email' => $email,
                'address' => $clientAddress,
            ]);
            $this->updateBranch($branchId, [
                'code' => $branchCode,
                'emission_point' => $emissionPoint,
                'branch_name' => $branchName,
                'address' => $branchAddress,
                'api_test' => $apiTest,
                'api_produccion' => $apiProduction,
                'reintentos_test' => $retryTest,
                'reintentos_produccion' => $retryProduction,
                'certificate_password' => $certificatePassword !== null && $certificatePassword !== ''
                    ? $certificatePassword
                    : $current['certificate_password'],
            ]);
            $this->upsertRetrySetting('pruebas', $testRetry);
            $this->upsertRetrySetting('produccion', $productionRetry);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->getConfiguration($clientContext, $baseConfig);
    }

    public function uploadCertificate(array $clientContext, array $upload, string $password, array $baseConfig): array
    {
        $row = $this->requireBranchRow($clientContext);
        $branchId = (int) $row['branch_id'];
        $password = trim($password);
        if ($password === '') {
            throw new InvalidArgumentException('Ingresa la contraseña del certificado .p12.');
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if (empty($upload['tmp_name']) || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw new InvalidArgumentException('El certificado no llego correctamente al servidor.');
        }

        $originalName = basename((string) ($upload['name'] ?? 'certificado.p12'));
        if (!preg_match('/\.p12$/i', $originalName)) {
            throw new InvalidArgumentException('El certificado debe tener extension .p12.');
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::CERTIFICATE_MAX_BYTES) {
            throw new InvalidArgumentException('El certificado .p12 debe pesar hasta 10 MB.');
        }

        $parsed = $this->parsePkcs12Certificate((string) $upload['tmp_name'], $password);
        if ($parsed === null) {
            throw new InvalidArgumentException('No se pudo leer el certificado. Revisa el archivo .p12 y su contraseña.');
        }

        if (!is_dir(self::CERTIFICATE_UPLOAD_DIR) && !mkdir(self::CERTIFICATE_UPLOAD_DIR, 0770, true) && !is_dir(self::CERTIFICATE_UPLOAD_DIR)) {
            throw new RuntimeException('No se pudo crear la carpeta de certificados.');
        }

        $fileName = sprintf('branch_%d_%s_%s.p12', $branchId, gmdate('YmdHis'), bin2hex(random_bytes(4)));
        $destination = self::CERTIFICATE_UPLOAD_DIR . '/' . $fileName;
        if (!move_uploaded_file((string) $upload['tmp_name'], $destination) || !is_file($destination)) {
            throw new RuntimeException('No se pudo guardar el certificado en el servidor.');
        }
        @chmod($destination, 0600);

        $statement = $this->connection->prepare(
            'UPDATE client_branches
             SET certificate_path = :certificate_path,
                 certificate_password = :certificate_password,
                 updated_at = NOW()
             WHERE id = :branch_id'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'certificate_path' => $destination,
            'certificate_password' => $password,
        ]);

        return $this->getConfiguration($clientContext, $baseConfig);
    }

    public function createBranch(array $clientContext, array $payload, array $baseConfig): array
    {
        $current = $this->requireBranchRow($clientContext);
        $clientId = (int) $current['client_id'];
        $branch = is_array($payload['branch'] ?? null) ? $payload['branch'] : $payload;
        $code = $this->normalizeThreeDigitCode($branch['code'] ?? null, 'Codigo de establecimiento');
        $emissionPoint = $this->normalizeThreeDigitCode($branch['emission_point'] ?? null, 'Punto de emision');
        $name = $this->optionalString($branch['name'] ?? $branch['branch_name'] ?? null);
        $address = $this->requiredString($branch['address'] ?? null, 'Direccion de sucursal requerida.');
        $isDefault = $this->payloadBool($branch['is_default'] ?? null, false);
        $isActive = $this->payloadBool($branch['is_active'] ?? null, true);
        $apiTest = $this->payloadBool($branch['api_test'] ?? $branch['apiTest'] ?? null, $current['api_test']);
        $apiProduction = $this->payloadBool($branch['api_production'] ?? $branch['api_produccion'] ?? $branch['apiProduction'] ?? null, $current['api_produccion']);
        $retryTest = $this->payloadBool($branch['retries_test'] ?? $branch['reintentos_test'] ?? null, $current['reintentos_test']);
        $retryProduction = $this->payloadBool($branch['retries_production'] ?? $branch['reintentos_produccion'] ?? null, $current['reintentos_produccion']);

        $this->assertUniqueBranchCode($clientId, $code, $emissionPoint, 0);
        if ($isDefault && !$isActive) {
            throw new InvalidArgumentException('La sucursal por defecto debe estar activa.');
        }

        $this->connection->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefaultBranches($clientId);
            }
            $statement = $this->connection->prepare(
                'INSERT INTO client_branches (
                    client_id,
                    code,
                    emission_point,
                    branch_name,
                    address,
                    logo_path,
                    certificate_path,
                    certificate_password,
                    mail_enabled,
                    mail_host,
                    mail_port,
                    mail_encryption,
                    mail_username,
                    mail_password,
                    mail_from_address,
                    mail_from_name,
                    reply_to_address,
                    reply_to_name,
                    api_test,
                    api_produccion,
                    reintentos_test,
                    reintentos_produccion,
                    is_default,
                    is_active
                ) VALUES (
                    :client_id,
                    :code,
                    :emission_point,
                    :branch_name,
                    :address,
                    :logo_path,
                    :certificate_path,
                    :certificate_password,
                    :mail_enabled,
                    :mail_host,
                    :mail_port,
                    :mail_encryption,
                    :mail_username,
                    :mail_password,
                    :mail_from_address,
                    :mail_from_name,
                    :reply_to_address,
                    :reply_to_name,
                    :api_test,
                    :api_produccion,
                    :reintentos_test,
                    :reintentos_produccion,
                    :is_default,
                    :is_active
                )'
            );
            $statement->execute([
                'client_id' => $clientId,
                'code' => $code,
                'emission_point' => $emissionPoint,
                'branch_name' => $name,
                'address' => $address,
                'logo_path' => $current['logo_path'] ?? null,
                'certificate_path' => $current['certificate_path'],
                'certificate_password' => $current['certificate_password'],
                'mail_enabled' => $current['mail_enabled'] ?? null,
                'mail_host' => $current['mail_host'] ?? null,
                'mail_port' => $current['mail_port'] ?? null,
                'mail_encryption' => $current['mail_encryption'] ?? null,
                'mail_username' => $current['mail_username'] ?? null,
                'mail_password' => $current['mail_password'] ?? null,
                'mail_from_address' => $current['mail_from_address'] ?? null,
                'mail_from_name' => $current['mail_from_name'] ?? null,
                'reply_to_address' => $current['reply_to_address'] ?? null,
                'reply_to_name' => $current['reply_to_name'] ?? null,
                'api_test' => $apiTest ? 1 : 0,
                'api_produccion' => $apiProduction ? 1 : 0,
                'reintentos_test' => $retryTest ? 1 : 0,
                'reintentos_produccion' => $retryProduction ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
            ]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->getConfiguration($clientContext, $baseConfig);
    }

    public function updateFiscalBranch(array $clientContext, int $branchId, array $payload, array $baseConfig): array
    {
        $current = $this->requireBranchRow($clientContext);
        $clientId = (int) $current['client_id'];
        $existing = $this->requireBranchForClient($clientId, $branchId);
        $branch = is_array($payload['branch'] ?? null) ? $payload['branch'] : $payload;
        $code = $this->normalizeThreeDigitCode($branch['code'] ?? $existing['code'], 'Codigo de establecimiento');
        $emissionPoint = $this->normalizeThreeDigitCode($branch['emission_point'] ?? $existing['emission_point'], 'Punto de emision');
        $name = $this->optionalString($branch['name'] ?? $branch['branch_name'] ?? $existing['branch_name']);
        $address = $this->requiredString($branch['address'] ?? $existing['address'], 'Direccion de sucursal requerida.');
        $isDefault = $this->payloadBool($branch['is_default'] ?? null, $existing['is_default']);
        $isActive = $this->payloadBool($branch['is_active'] ?? null, $existing['is_active']);
        $apiTest = $this->payloadBool($branch['api_test'] ?? $branch['apiTest'] ?? null, $existing['api_test']);
        $apiProduction = $this->payloadBool($branch['api_production'] ?? $branch['api_produccion'] ?? $branch['apiProduction'] ?? null, $existing['api_produccion']);
        $retryTest = $this->payloadBool($branch['retries_test'] ?? $branch['reintentos_test'] ?? null, $existing['reintentos_test']);
        $retryProduction = $this->payloadBool($branch['retries_production'] ?? $branch['reintentos_produccion'] ?? null, $existing['reintentos_produccion']);

        $this->assertUniqueBranchCode($clientId, $code, $emissionPoint, $branchId);
        if ($isDefault && !$isActive) {
            throw new InvalidArgumentException('La sucursal por defecto debe estar activa.');
        }
        if (!$isDefault && $this->dbBool($existing['is_default'] ?? false)) {
            throw new InvalidArgumentException('Debe quedar una sucursal fiscal por defecto. Marca otra sucursal como por defecto antes de cambiar esta.');
        }
        if (!$isActive && $this->dbBool($existing['is_default'] ?? false)) {
            throw new InvalidArgumentException('No puedes desactivar la sucursal por defecto. Marca otra sucursal como por defecto antes de desactivarla.');
        }
        if (!$isActive && $this->activeBranchCount($clientId) <= 1 && $this->dbBool($existing['is_active'] ?? false)) {
            throw new InvalidArgumentException('Debe quedar al menos una sucursal fiscal activa.');
        }

        $this->connection->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefaultBranches($clientId);
            }
            $statement = $this->connection->prepare(
                'UPDATE client_branches
                 SET code = :code,
                     emission_point = :emission_point,
                     branch_name = :branch_name,
                     address = :address,
                     api_test = :api_test,
                     api_produccion = :api_produccion,
                     reintentos_test = :reintentos_test,
                     reintentos_produccion = :reintentos_produccion,
                     is_default = :is_default,
                     is_active = :is_active,
                     updated_at = NOW()
                 WHERE id = :branch_id
                   AND client_id = :client_id'
            );
            $statement->execute([
                'branch_id' => $branchId,
                'client_id' => $clientId,
                'code' => $code,
                'emission_point' => $emissionPoint,
                'branch_name' => $name,
                'address' => $address,
                'api_test' => $apiTest ? 1 : 0,
                'api_produccion' => $apiProduction ? 1 : 0,
                'reintentos_test' => $retryTest ? 1 : 0,
                'reintentos_produccion' => $retryProduction ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
            ]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->getConfiguration($clientContext, $baseConfig);
    }

    private function buildConfigurationPayload(array $row, array $retrySettings, array $baseConfig): array
    {
        $testRetry = $retrySettings['pruebas'] ?? $this->defaultRetrySetting('pruebas');
        $productionRetry = $retrySettings['produccion'] ?? $this->defaultRetrySetting('produccion');

        return [
            'client' => [
                'id' => (int) $row['client_id'],
                'ruc' => (string) $row['ruc'],
                'business_name' => (string) $row['business_name'],
                'trade_name' => (string) ($row['trade_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'address' => (string) ($row['client_address'] ?? ''),
            ],
            'branch' => [
                'id' => (int) $row['branch_id'],
                'code' => (string) $row['branch_code'],
                'emission_point' => (string) $row['emission_point'],
                'name' => (string) ($row['branch_name'] ?? ''),
                'address' => (string) ($row['branch_address'] ?? ''),
                'is_default' => $this->dbBool($row['is_default'] ?? false),
                'is_active' => $this->dbBool($row['branch_is_active'] ?? false),
            ],
            'api_key' => [
                'name' => $row['api_key_name'] ?? null,
                'prefix' => $row['key_prefix'] ?? null,
                'last_used_at' => $row['api_key_last_used_at'] ?? null,
            ],
            'branches' => $this->branchesForClient((int) $row['client_id']),
            'environments' => [
                'test' => [
                    'label' => 'QA / pruebas',
                    'sri_environment' => 'pruebas',
                    'enabled' => $this->dbBool($row['api_test'] ?? false),
                    'recepcion_wsdl' => $baseConfig['web_services']['pruebas']['recepcion'] ?? null,
                    'autorizacion_wsdl' => $baseConfig['web_services']['pruebas']['autorizacion'] ?? null,
                ],
                'production' => [
                    'label' => 'Produccion',
                    'sri_environment' => 'produccion',
                    'enabled' => $this->dbBool($row['api_produccion'] ?? false),
                    'recepcion_wsdl' => $baseConfig['web_services']['produccion']['recepcion'] ?? null,
                    'autorizacion_wsdl' => $baseConfig['web_services']['produccion']['autorizacion'] ?? null,
                ],
            ],
            'retries' => [
                'test' => [
                    'enabled' => $this->dbBool($row['reintentos_test'] ?? false),
                    ...$this->retryPayload($testRetry),
                ],
                'production' => [
                    'enabled' => $this->dbBool($row['reintentos_produccion'] ?? false),
                    ...$this->retryPayload($productionRetry),
                ],
            ],
            'certificate' => $this->certificateMetadata($row['certificate_path'] ?? null, $row['certificate_password'] ?? null),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function requireBranchRow(array $clientContext): array
    {
        $clientId = (int) ($clientContext['client_id'] ?? 0);
        $branchId = (int) ($clientContext['resolved_branch_id'] ?? $clientContext['branch_id'] ?? 0);
        if ($clientId <= 0 || $branchId <= 0) {
            throw new InvalidArgumentException('No se pudo resolver el cliente fiscal autenticado.');
        }

        $statement = $this->connection->prepare(
            'SELECT
                c.id AS client_id,
                c.ruc,
                c.business_name,
                c.trade_name,
                c.email,
                c.address AS client_address,
                b.id AS branch_id,
                b.code AS branch_code,
                b.emission_point,
                b.branch_name,
                b.address AS branch_address,
                b.logo_path,
                b.certificate_path,
                b.certificate_password,
                b.mail_enabled,
                b.mail_host,
                b.mail_port,
                b.mail_encryption,
                b.mail_username,
                b.mail_password,
                b.mail_from_address,
                b.mail_from_name,
                b.reply_to_address,
                b.reply_to_name,
                b.api_test,
                b.api_produccion,
                b.reintentos_test,
                b.reintentos_produccion,
                b.is_default,
                b.is_active AS branch_is_active,
                b.updated_at,
                ak.name AS api_key_name,
                ak.key_prefix,
                ak.last_used_at AS api_key_last_used_at
             FROM clients c
             INNER JOIN client_branches b ON b.client_id = c.id
             LEFT JOIN api_keys ak ON ak.id = :api_key_id
             WHERE c.id = :client_id
               AND b.id = :branch_id
             LIMIT 1'
        );
        $statement->execute([
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'api_key_id' => (int) ($clientContext['api_key_id'] ?? 0),
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('No se encontro la configuracion fiscal del cliente autenticado.');
        }

        return $row;
    }

    private function branchesForClient(int $clientId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                id,
                code,
                emission_point,
                branch_name,
                address,
                api_test,
                api_produccion,
                reintentos_test,
                reintentos_produccion,
                is_default,
                is_active
             FROM client_branches
             WHERE client_id = :client_id
             ORDER BY is_default DESC, code ASC, emission_point ASC, id ASC'
        );
        $statement->execute(['client_id' => $clientId]);
        $rows = $statement->fetchAll();
        $branches = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $branches[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'emission_point' => (string) $row['emission_point'],
                'name' => (string) ($row['branch_name'] ?? ''),
                'address' => (string) ($row['address'] ?? ''),
                'api_test' => $this->dbBool($row['api_test'] ?? false),
                'api_production' => $this->dbBool($row['api_produccion'] ?? false),
                'retries_test' => $this->dbBool($row['reintentos_test'] ?? false),
                'retries_production' => $this->dbBool($row['reintentos_produccion'] ?? false),
                'is_default' => $this->dbBool($row['is_default'] ?? false),
                'is_active' => $this->dbBool($row['is_active'] ?? false),
            ];
        }

        return $branches;
    }

    private function requireBranchForClient(int $clientId, int $branchId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                id,
                code,
                emission_point,
                branch_name,
                address,
                api_test,
                api_produccion,
                reintentos_test,
                reintentos_produccion,
                is_default,
                is_active
             FROM client_branches
             WHERE client_id = :client_id
               AND id = :branch_id
             LIMIT 1'
        );
        $statement->execute([
            'client_id' => $clientId,
            'branch_id' => $branchId,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Sucursal fiscal no encontrada para este cliente.');
        }

        return $row;
    }

    private function activeBranchCount(int $clientId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM client_branches
             WHERE client_id = :client_id
               AND is_active = TRUE'
        );
        $statement->execute(['client_id' => $clientId]);

        return (int) $statement->fetchColumn();
    }

    private function clearDefaultBranches(int $clientId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE client_branches
             SET is_default = FALSE,
                 updated_at = NOW()
             WHERE client_id = :client_id'
        );
        $statement->execute(['client_id' => $clientId]);
    }

    private function retrySettingsByEnvironment(): array
    {
        $statement = $this->connection->query(
            'SELECT ambiente, max_retry_days, max_attempts, delay_seconds, is_active
             FROM invoice_retry_settings'
        );
        $rows = $statement ? $statement->fetchAll() : [];
        $settings = [
            'pruebas' => $this->defaultRetrySetting('pruebas'),
            'produccion' => $this->defaultRetrySetting('produccion'),
        ];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ambiente = (string) ($row['ambiente'] ?? '');
            if (isset($settings[$ambiente])) {
                $settings[$ambiente] = [
                    'ambiente' => $ambiente,
                    'max_retry_days' => (int) ($row['max_retry_days'] ?? 5),
                    'max_attempts' => (int) ($row['max_attempts'] ?? 3),
                    'delay_seconds' => max(self::MIN_RETRY_DELAY_SECONDS, (int) ($row['delay_seconds'] ?? self::MIN_RETRY_DELAY_SECONDS)),
                    'is_active' => $this->dbBool($row['is_active'] ?? true),
                ];
            }
        }

        return $settings;
    }

    private function defaultRetrySetting(string $ambiente): array
    {
        return [
            'ambiente' => $ambiente,
            'max_retry_days' => 5,
            'max_attempts' => 3,
            'delay_seconds' => self::MIN_RETRY_DELAY_SECONDS,
            'is_active' => true,
        ];
    }

    private function retryPayload(array $setting): array
    {
        return [
            'is_active' => $this->dbBool($setting['is_active'] ?? true),
            'max_retry_days' => (int) ($setting['max_retry_days'] ?? 5),
            'max_attempts' => (int) ($setting['max_attempts'] ?? 3),
            'delay_seconds' => max(self::MIN_RETRY_DELAY_SECONDS, (int) ($setting['delay_seconds'] ?? self::MIN_RETRY_DELAY_SECONDS)),
        ];
    }

    private function normalizeRetryPayload(mixed $payload, array $current, string $ambiente): array
    {
        $data = is_array($payload) ? $payload : [];

        return [
            'ambiente' => $ambiente,
            'is_active' => $this->payloadBool($data['is_active'] ?? null, $current['is_active'] ?? true),
            'max_retry_days' => $this->boundedInt($data['max_retry_days'] ?? $current['max_retry_days'] ?? 5, 0, 365, 'La ventana de reintentos debe estar entre 0 y 365 dias.'),
            'max_attempts' => $this->boundedInt($data['max_attempts'] ?? $current['max_attempts'] ?? 3, 1, 20, 'Los intentos deben estar entre 1 y 20.'),
            'delay_seconds' => $this->boundedInt(
                $data['delay_seconds'] ?? $current['delay_seconds'] ?? self::MIN_RETRY_DELAY_SECONDS,
                self::MIN_RETRY_DELAY_SECONDS,
                86400,
                'La espera entre reintentos debe estar entre 3600 y 86400 segundos.'
            ),
        ];
    }

    private function upsertRetrySetting(string $ambiente, array $setting): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO invoice_retry_settings (ambiente, max_retry_days, max_attempts, delay_seconds, is_active)
             VALUES (:ambiente, :max_retry_days, :max_attempts, :delay_seconds, :is_active)
             ON CONFLICT (ambiente) DO UPDATE SET
                max_retry_days = EXCLUDED.max_retry_days,
                max_attempts = EXCLUDED.max_attempts,
                delay_seconds = EXCLUDED.delay_seconds,
                is_active = EXCLUDED.is_active,
                updated_at = NOW()'
        );
        $statement->execute([
            'ambiente' => $ambiente,
            'max_retry_days' => (int) $setting['max_retry_days'],
            'max_attempts' => (int) $setting['max_attempts'],
            'delay_seconds' => (int) $setting['delay_seconds'],
            'is_active' => (bool) $setting['is_active'] ? 1 : 0,
        ]);
    }

    private function updateClient(int $clientId, array $values): void
    {
        $statement = $this->connection->prepare(
            'UPDATE clients
             SET ruc = :ruc,
                 business_name = :business_name,
                 trade_name = :trade_name,
                 email = :email,
                 address = :address,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $clientId,
            ...$values,
        ]);
    }

    private function updateBranch(int $branchId, array $values): void
    {
        $statement = $this->connection->prepare(
            'UPDATE client_branches
             SET code = :code,
                 emission_point = :emission_point,
                 branch_name = :branch_name,
                 address = :address,
                 api_test = :api_test,
                 api_produccion = :api_produccion,
                 reintentos_test = :reintentos_test,
                 reintentos_produccion = :reintentos_produccion,
                 certificate_password = :certificate_password,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $branchId,
            'code' => $values['code'],
            'emission_point' => $values['emission_point'],
            'branch_name' => $values['branch_name'],
            'address' => $values['address'],
            'api_test' => (bool) $values['api_test'] ? 1 : 0,
            'api_produccion' => (bool) $values['api_produccion'] ? 1 : 0,
            'reintentos_test' => (bool) $values['reintentos_test'] ? 1 : 0,
            'reintentos_produccion' => (bool) $values['reintentos_produccion'] ? 1 : 0,
            'certificate_password' => $values['certificate_password'],
        ]);
    }

    private function assertUniqueClientRuc(string $ruc, int $clientId): void
    {
        $statement = $this->connection->prepare('SELECT id FROM clients WHERE ruc = :ruc AND id <> :client_id LIMIT 1');
        $statement->execute(['ruc' => $ruc, 'client_id' => $clientId]);
        if ($statement->fetch()) {
            throw new InvalidArgumentException('Ya existe otro cliente registrado con ese RUC.');
        }
    }

    private function assertUniqueBranchCode(int $clientId, string $code, string $emissionPoint, int $branchId): void
    {
        $statement = $this->connection->prepare(
            'SELECT id
             FROM client_branches
             WHERE client_id = :client_id
               AND code = :code
               AND emission_point = :emission_point
               AND id <> :branch_id
             LIMIT 1'
        );
        $statement->execute([
            'client_id' => $clientId,
            'code' => $code,
            'emission_point' => $emissionPoint,
            'branch_id' => $branchId,
        ]);
        if ($statement->fetch()) {
            throw new InvalidArgumentException('Ya existe otra sucursal con ese establecimiento y punto de emision.');
        }
    }

    private function validateStoredCertificatePassword(?string $path, string $password): void
    {
        $resolvedPath = $this->resolveCertificateFilesystemPath($path);
        if ($resolvedPath === null || !is_file($resolvedPath)) {
            throw new InvalidArgumentException('No se encontro el certificado actual para validar la contraseña.');
        }
        if ($this->parsePkcs12Certificate($resolvedPath, $password) === null) {
            throw new InvalidArgumentException('La contraseña del certificado no coincide con el archivo actual.');
        }
    }

    private function certificateMetadata(?string $path, ?string $password): array
    {
        $metadata = [
            'file_name' => $path ? basename($path) : null,
            'subject' => null,
            'valid_from' => null,
            'expires_at' => null,
            'days_remaining' => null,
            'status' => 'missing',
            'label' => 'Sin certificado',
            'message' => 'No hay certificado .p12 configurado.',
            'password_configured' => $password !== null && trim($password) !== '',
        ];

        if ($path === null || trim($path) === '' || $password === null || trim($password) === '') {
            return $metadata;
        }

        $resolvedPath = $this->resolveCertificateFilesystemPath($path);
        if ($resolvedPath === null || !is_file($resolvedPath)) {
            return [
                ...$metadata,
                'status' => 'missing',
                'label' => 'No encontrado',
                'message' => 'El archivo configurado no existe en el servidor.',
            ];
        }

        $parsed = $this->parsePkcs12Certificate($resolvedPath, $password);
        if ($parsed === null) {
            return [
                ...$metadata,
                'status' => 'unknown',
                'label' => 'No verificado',
                'message' => 'No fue posible leer el certificado con la contraseña guardada.',
            ];
        }

        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $daysRemaining = $validTo !== null ? (int) floor(($validTo - time()) / 86400) : null;
        $metadata = [
            ...$metadata,
            'subject' => $parsed['subject']['CN'] ?? null,
            'valid_from' => $validFrom ? gmdate(DATE_ATOM, $validFrom) : null,
            'expires_at' => $validTo ? gmdate(DATE_ATOM, $validTo) : null,
            'days_remaining' => $daysRemaining,
        ];

        if ($validTo === null) {
            return [
                ...$metadata,
                'status' => 'unknown',
                'label' => 'No verificado',
                'message' => 'No fue posible determinar el vencimiento del certificado.',
            ];
        }
        if ($validTo < time()) {
            return [
                ...$metadata,
                'status' => 'expired',
                'label' => 'Vencido',
                'message' => sprintf('El certificado vencio hace %d dia(s).', abs($daysRemaining ?? 0)),
            ];
        }
        if ($daysRemaining !== null && $daysRemaining <= 30) {
            return [
                ...$metadata,
                'status' => 'expiring',
                'label' => 'Proximo a vencer',
                'message' => sprintf('El certificado vence en %d dia(s).', $daysRemaining),
            ];
        }

        return [
            ...$metadata,
            'status' => 'valid',
            'label' => 'Vigente',
            'message' => sprintf('El certificado esta vigente y vence en %d dia(s).', $daysRemaining ?? 0),
        ];
    }

    private function parsePkcs12Certificate(string $path, string $password): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents !== false) {
            $certificates = [];
            if (@openssl_pkcs12_read($contents, $certificates, $password) && !empty($certificates['cert'])) {
                $parsed = @openssl_x509_parse($certificates['cert']);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        $tempPemPath = tempnam(sys_get_temp_dir(), 'cert_meta_');
        if ($tempPemPath === false) {
            return null;
        }

        $process = @proc_open(
            [
                'openssl',
                'pkcs12',
                '-in',
                $path,
                '-clcerts',
                '-nokeys',
                '-out',
                $tempPemPath,
                '-passin',
                'env:PM_CERT_PASSWORD',
                '-legacy',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            [
                'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PM_CERT_PASSWORD' => $password,
            ]
        );
        if (!is_resource($process)) {
            @unlink($tempPemPath);
            return null;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_file($tempPemPath)) {
            @unlink($tempPemPath);
            return null;
        }

        $certificatePem = @file_get_contents($tempPemPath);
        @unlink($tempPemPath);
        if ($certificatePem === false) {
            return null;
        }

        $parsed = @openssl_x509_parse($certificatePem);
        return is_array($parsed) ? $parsed : null;
    }

    private function resolveCertificateFilesystemPath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        if (str_starts_with($path, '/app/storage/')) {
            $candidate = str_replace('/app/storage', '/var/www/html/storage/billing', $path);
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (str_starts_with($path, '/app/certs/') || str_starts_with($path, '/app/entorno/certs/')) {
            $candidate = '/var/www/html/storage/billing/certs/' . basename($path);
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (str_starts_with($path, '/var/www/html/storage/billing/certs/')) {
            $candidate = '/var/www/html/storage/billing/certs/' . basename($path);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $path;
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'La carga del archivo se completo de forma parcial.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene directorio temporal para cargas.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo.',
            UPLOAD_ERR_EXTENSION => 'La carga fue detenida por una extension de PHP.',
            default => 'No se pudo procesar el archivo subido.',
        };
    }

    private function normalizeRuc(mixed $value): string
    {
        $ruc = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (!preg_match('/^\d{13}$/', $ruc)) {
            throw new InvalidArgumentException('El RUC debe tener 13 digitos.');
        }

        return $ruc;
    }

    private function normalizeThreeDigitCode(mixed $value, string $label): string
    {
        $code = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (!preg_match('/^\d{1,3}$/', $code)) {
            throw new InvalidArgumentException($label . ' debe tener hasta 3 digitos.');
        }

        return str_pad($code, 3, '0', STR_PAD_LEFT);
    }

    private function requiredString(mixed $value, string $message): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function payloadBool(mixed $value, mixed $default): bool
    {
        if ($value === null) {
            return $this->dbBool($default);
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si', 'on', 't'], true);
    }

    private function dbBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'y', 'yes', 'si', 'on'], true);
    }

    private function boundedInt(mixed $value, int $min, int $max, string $message): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($message);
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new InvalidArgumentException($message);
        }

        return $number;
    }
}
