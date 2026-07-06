<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

use Firebase\JWT\JWT;

/**
 * Obtiene access tokens OAuth2 del service account (grant jwt-bearer).
 *
 * Cache en dos niveles: estatico por proceso y archivo en tmp (tmpfs del
 * contenedor) con escritura atomica. El token nunca se persiste en DB.
 */
final class GoogleOAuthTokenProvider {
    public const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var array<string, array{access_token: string, expires_at: int}> */
    private static array $memoryCache = [];

    private readonly string $cacheDir;

    public function __construct(
        private readonly GoogleServiceAccount $account,
        private readonly GoogleWalletHttpClient $http,
        ?string $cacheDir = null
    ) {
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir();
    }

    public function accessToken(): string {
        $cacheKey = sha1($this->account->clientEmail() . '|' . self::SCOPE);

        $cached = self::$memoryCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['access_token'];
        }

        $fromFile = $this->readCacheFile();
        if ($fromFile !== null && $fromFile['expires_at'] > time()) {
            self::$memoryCache[$cacheKey] = $fromFile;
            return $fromFile['access_token'];
        }

        $fresh = $this->fetchToken();
        self::$memoryCache[$cacheKey] = $fresh;
        $this->writeCacheFile($fresh);

        return $fresh['access_token'];
    }

    /** @return array{access_token: string, expires_at: int} */
    private function fetchToken(): array {
        $now = time();
        $assertion = JWT::encode(
            [
                'iss' => $this->account->clientEmail(),
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            $this->account->privateKey(),
            'RS256'
        );

        $response = $this->http->postForm(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        if ($response['status'] >= 400 || !is_array($response['json'])) {
            throw GoogleWalletException::api($response['status'], $response['json']);
        }

        $accessToken = trim((string)($response['json']['access_token'] ?? ''));
        $expiresIn = (int)($response['json']['expires_in'] ?? 0);
        if ($accessToken === '' || $expiresIn <= 0) {
            throw GoogleWalletException::api($response['status'], ['error' => ['message' => 'respuesta de token invalida']]);
        }

        return [
            'access_token' => $accessToken,
            'expires_at' => $now + $expiresIn - 300,
        ];
    }

    private function cacheFile(): string {
        return rtrim($this->cacheDir, '/') . '/gwtoken-' . sha1($this->account->clientEmail() . '|' . self::SCOPE) . '.json';
    }

    /** @return ?array{access_token: string, expires_at: int} */
    private function readCacheFile(): ?array {
        $file = $this->cacheFile();
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_at'])) {
            return null;
        }

        return [
            'access_token' => (string)$data['access_token'],
            'expires_at' => (int)$data['expires_at'],
        ];
    }

    /** @param array{access_token: string, expires_at: int} $token */
    private function writeCacheFile(array $token): void {
        $tmp = tempnam($this->cacheDir, 'gwtok');
        if ($tmp === false) {
            return; // cache best-effort: sin archivo seguimos funcionando
        }

        file_put_contents($tmp, json_encode($token));
        chmod($tmp, 0600);
        @rename($tmp, $this->cacheFile());
    }
}
