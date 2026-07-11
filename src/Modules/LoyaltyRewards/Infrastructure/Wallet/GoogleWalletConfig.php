<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/**
 * Configuracion efectiva de Google Wallet para un tenant.
 *
 * Combina variables de entorno globales (GOOGLE_WALLET_ISSUER_ID,
 * GOOGLE_WALLET_SA_PATH) con la seccion googleWallet de
 * loyalty_program_settings.settings del tenant.
 */
final class GoogleWalletConfig {
    private function __construct(
        private readonly string $tenantId,
        private readonly string $issuerId,
        private readonly string $serviceAccountPath,
        private readonly bool $enabled,
        private readonly string $classSuffix,
        private readonly string $issuerName,
        private readonly string $programName,
        private readonly string $hexBackgroundColor,
        private readonly string $logoUrl,
        private readonly string $pointsLabel,
        private readonly array $origins
    ) {}

    public static function fromEnvAndSettings(string $tenantId, array $settings, array $program): self {
        $wallet = is_array($settings['googleWallet'] ?? null) ? $settings['googleWallet'] : [];

        $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));

        $origins = [];
        foreach ((array)($wallet['origins'] ?? []) as $origin) {
            $origin = trim((string)$origin);
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return new self(
            tenantId: $tenantId,
            issuerId: $env('GOOGLE_WALLET_ISSUER_ID'),
            serviceAccountPath: $env('GOOGLE_WALLET_SA_PATH'),
            enabled: filter_var($wallet['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            classSuffix: self::sanitizeSuffix(trim((string)($wallet['classSuffix'] ?? ''))),
            issuerName: trim((string)($wallet['issuerName'] ?? '')) ?: 'TecnoLTS',
            programName: trim((string)($wallet['programName'] ?? '')) ?: trim((string)($program['name'] ?? '')) ?: 'Programa de fidelizacion',
            hexBackgroundColor: trim((string)($wallet['hexBackgroundColor'] ?? '')) ?: trim((string)($program['brand_color'] ?? '')) ?: '#2b648f',
            logoUrl: trim((string)($wallet['logoUrl'] ?? '')) ?: trim((string)($program['logo_url'] ?? '')),
            pointsLabel: trim((string)($wallet['pointsLabel'] ?? '')) ?: 'Puntos',
            origins: $origins
        );
    }

    public function isConfigured(): bool {
        return $this->missing() === [];
    }

    /** @return array<int, string> requisitos faltantes, para mensajes 422 */
    public function missing(): array {
        $missing = [];
        if (!$this->enabled) {
            $missing[] = 'settings.googleWallet.enabled';
        }
        if ($this->issuerId === '') {
            $missing[] = 'GOOGLE_WALLET_ISSUER_ID';
        }
        if ($this->serviceAccountPath === '' || !is_readable($this->serviceAccountPath)) {
            $missing[] = 'GOOGLE_WALLET_SA_PATH (archivo legible)';
        }
        if ($this->classSuffix === '') {
            $missing[] = 'settings.googleWallet.classSuffix';
        }
        if ($this->logoUrl === '') {
            $missing[] = 'settings.googleWallet.logoUrl';
        }

        return $missing;
    }

    public function issuerId(): string {
        return $this->issuerId;
    }

    public function serviceAccountPath(): string {
        return $this->serviceAccountPath;
    }

    public function classId(): string {
        return $this->issuerId . '.' . $this->classSuffix;
    }

    /**
     * ObjectId canonico y deterministico: {issuerId}.{tenant_accountId sanitizado}.
     * Incluir el tenant evita colisiones de account_id entre tenants del mismo issuer.
     */
    public function objectId(string $accountId): string {
        return $this->issuerId . '.' . self::sanitizeSuffix($this->tenantId . '_' . $accountId);
    }

    public function issuerName(): string {
        return $this->issuerName;
    }

    public function programName(): string {
        return $this->programName;
    }

    public function hexBackgroundColor(): string {
        return $this->hexBackgroundColor;
    }

    public function logoUrl(): string {
        return $this->logoUrl;
    }

    public function pointsLabel(): string {
        return $this->pointsLabel;
    }

    /** @return array<int, string> */
    public function origins(): array {
        return $this->origins;
    }

    /** Charset permitido por Google para ids: alfanumerico, punto, guion y guion bajo. */
    private static function sanitizeSuffix(string $raw): string {
        return (string)preg_replace('/[^A-Za-z0-9._-]/', '-', $raw);
    }
}
