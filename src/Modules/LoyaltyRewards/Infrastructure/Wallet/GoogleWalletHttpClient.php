<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/**
 * Cliente cURL minimo para OAuth y la API REST de Wallet.
 *
 * HTTP >= 400 NO lanza excepcion: devuelve el status y el caller decide
 * (el flujo patch-404-insert lo necesita). Solo lanza en errores de red.
 */
final class GoogleWalletHttpClient {
    public function __construct(
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $timeoutSeconds = 8
    ) {}

    /**
     * @param array<int, string> $headers
     * @return array{status: int, json: ?array, raw: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw GoogleWalletException::network('no se pudo inicializar cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            throw GoogleWalletException::network(curl_error($handle) ?: 'fallo desconocido de cURL.');
        }

        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        $decoded = json_decode((string)$raw, true);

        return [
            'status' => $status,
            'json' => is_array($decoded) ? $decoded : null,
            'raw' => (string)$raw,
        ];
    }

    /**
     * POST application/x-www-form-urlencoded (intercambio de token OAuth).
     *
     * @param array<string, string> $fields
     * @return array{status: int, json: ?array, raw: string}
     */
    public function postForm(string $url, array $fields): array {
        return $this->request(
            'POST',
            $url,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($fields)
        );
    }
}
