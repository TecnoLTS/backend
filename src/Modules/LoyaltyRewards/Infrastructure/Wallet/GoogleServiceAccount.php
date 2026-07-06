<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/**
 * Credenciales del service account de Google (archivo JSON montado).
 *
 * La private key nunca sale de esta clase salvo por privateKey(); los mensajes
 * de error jamas incluyen contenido del archivo.
 */
final class GoogleServiceAccount {
    private function __construct(
        private readonly string $clientEmail,
        private readonly string $privateKey
    ) {}

    public static function load(string $path): self {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw GoogleWalletException::config('el archivo del service account no existe o no es legible.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw GoogleWalletException::config('no se pudo leer el archivo del service account.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw GoogleWalletException::config('el archivo del service account no es JSON valido.');
        }

        $clientEmail = trim((string)($data['client_email'] ?? ''));
        $privateKey = (string)($data['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            throw GoogleWalletException::config('el service account no define client_email o private_key.');
        }

        return new self($clientEmail, $privateKey);
    }

    public function clientEmail(): string {
        return $this->clientEmail;
    }

    public function privateKey(): string {
        return $this->privateKey;
    }

    /** Evita fugas de la private key en var_dump / trazas. */
    public function __debugInfo(): array {
        return [
            'clientEmail' => $this->clientEmail,
            'privateKey' => '[redacted]',
        ];
    }
}
