<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Persistence;

use PDO;

class ApiKeyRepository
{
    public function __construct(private readonly PDO $connection) {}

    public function findClientContextByRawKey(string $rawKey, ?string $requiredApiMode = null): ?array
    {
        $keyHash = hash('sha256', $rawKey);

        $statement = $this->connection->prepare(
            'SELECT
                ak.id AS api_key_id,
                ak.client_id,
                ak.branch_id AS api_key_branch_id,
                NULL::BIGINT AS branch_id,
                ak.name AS api_key_name,
                c.ruc AS client_ruc,
                c.business_name AS client_business_name,
                c.trade_name AS client_trade_name,
                c.phone AS client_phone,
                c.email AS client_email,
                c.address AS client_address,
                b.id AS resolved_branch_id,
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
                test_retry.max_retry_days AS retry_test_max_retry_days,
                test_retry.max_attempts AS retry_test_max_attempts,
                test_retry.delay_seconds AS retry_test_delay_seconds,
                test_retry.is_active AS retry_test_is_active,
                production_retry.max_retry_days AS retry_production_max_retry_days,
                production_retry.max_attempts AS retry_production_max_attempts,
                production_retry.delay_seconds AS retry_production_delay_seconds,
                production_retry.is_active AS retry_production_is_active
            FROM api_keys ak
            INNER JOIN clients c ON c.id = ak.client_id AND c.is_active = TRUE
            LEFT JOIN client_branches b ON b.id = COALESCE(
                ak.branch_id,
                (
                    SELECT b2.id
                    FROM client_branches b2
                    WHERE b2.client_id = c.id AND b2.is_default = TRUE AND b2.is_active = TRUE
                    ORDER BY b2.id ASC
                    LIMIT 1
                )
            )
            LEFT JOIN invoice_retry_settings test_retry ON test_retry.ambiente = \'pruebas\'
            LEFT JOIN invoice_retry_settings production_retry ON production_retry.ambiente = \'produccion\'
            WHERE ak.key_hash = :key_hash
              AND ak.revoked_at IS NULL
            LIMIT 1'
        );

        $statement->execute(['key_hash' => $keyHash]);
        $context = $statement->fetch();

        if (!is_array($context)) {
            return null;
        }

        if (empty($context['resolved_branch_id'])) {
            return null;
        }

        if ($requiredApiMode !== null && !$this->isApiModeEnabled($context, $requiredApiMode)) {
            return null;
        }

        return $context;
    }

    public function touchUsage(int $apiKeyId): void
    {
        $statement = $this->connection->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $apiKeyId]);
    }

    public function withResolvedBranch(
        array $context,
        ?int $branchId = null,
        ?string $branchCode = null,
        ?string $emissionPoint = null,
        ?string $requiredApiMode = null
    ): array {
        $branchCode = trim((string) $branchCode);
        $emissionPoint = trim((string) $emissionPoint);
        if (($branchId === null || $branchId <= 0) && $branchCode === '' && $emissionPoint === '') {
            if ($requiredApiMode !== null && !$this->isApiModeEnabled($context, $requiredApiMode)) {
                $message = $requiredApiMode === 'production'
                    ? 'La sucursal base no tiene habilitada la API produccion.'
                    : 'La sucursal base no tiene habilitada la API test.';
                throw new \InvalidArgumentException($message);
            }

            return $context;
        }

        $clientId = (int) ($context['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new \InvalidArgumentException('No se pudo resolver el cliente fiscal autenticado.');
        }

        $parameters = ['client_id' => $clientId];
        $where = 'b.client_id = :client_id AND b.is_active = TRUE';
        if ($branchId !== null && $branchId > 0) {
            $where .= ' AND b.id = :branch_id';
            $parameters['branch_id'] = $branchId;
        } else {
            if ($branchCode !== '') {
                $where .= ' AND b.code = :branch_code';
                $parameters['branch_code'] = str_pad(preg_replace('/\D+/', '', $branchCode) ?: $branchCode, 3, '0', STR_PAD_LEFT);
            }
            if ($emissionPoint !== '') {
                $where .= ' AND b.emission_point = :emission_point';
                $parameters['emission_point'] = str_pad(preg_replace('/\D+/', '', $emissionPoint) ?: $emissionPoint, 3, '0', STR_PAD_LEFT);
            }
        }

        $statement = $this->connection->prepare(
            'SELECT
                b.id AS resolved_branch_id,
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
                b.reintentos_produccion
             FROM client_branches b
             WHERE ' . $where . '
             ORDER BY b.is_default DESC, b.id ASC
             LIMIT 1'
        );
        $statement->execute($parameters);
        $branch = $statement->fetch();
        if (!is_array($branch)) {
            throw new \InvalidArgumentException('Sucursal fiscal no encontrada o inactiva para este cliente.');
        }

        $resolved = [
            ...$context,
            ...$branch,
        ];
        if ($requiredApiMode !== null && !$this->isApiModeEnabled($resolved, $requiredApiMode)) {
            $message = $requiredApiMode === 'production'
                ? 'La sucursal seleccionada no tiene habilitada la API produccion.'
                : 'La sucursal seleccionada no tiene habilitada la API test.';
            throw new \InvalidArgumentException($message);
        }

        return $resolved;
    }

    private function isApiModeEnabled(array $context, string $requiredApiMode): bool
    {
        return match ($requiredApiMode) {
            'test' => filter_var($context['api_test'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'production' => filter_var($context['api_produccion'] ?? false, FILTER_VALIDATE_BOOLEAN),
            default => false,
        };
    }
}
