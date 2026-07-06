<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/**
 * Error de integracion con Google Wallet (red, OAuth o API REST).
 *
 * No extiende RuntimeException a proposito: el respond() del modulo mapea
 * RuntimeException a 404; los errores de Google deben llegar como 500.
 */
final class GoogleWalletException extends \Exception {
    public readonly int $httpStatus;
    public readonly ?string $googleReason;

    private function __construct(string $message, int $httpStatus, ?string $googleReason = null) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->googleReason = $googleReason;
    }

    public static function network(string $detail): self {
        return new self('Error de red hacia Google Wallet: ' . $detail, 0);
    }

    public static function api(int $status, ?array $googleError = null): self {
        $reason = null;
        if (is_array($googleError)) {
            $reason = trim((string)($googleError['error']['message'] ?? $googleError['error_description'] ?? ''));
            $reason = $reason !== '' ? mb_substr($reason, 0, 300) : null;
        }

        $message = sprintf('Google Wallet respondio HTTP %d%s', $status, $reason !== null ? ': ' . $reason : '.');

        return new self($message, $status, $reason);
    }

    public static function config(string $detail): self {
        return new self('Configuracion de Google Wallet invalida: ' . $detail, 0);
    }
}
