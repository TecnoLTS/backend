<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

use Firebase\JWT\JWT;

/**
 * Operaciones contra Google Wallet: link "save to wallet", sincronizacion de
 * puntos (patch con insert en 404) y mensajes push al pase.
 *
 * Puerto del demo Node generadorCardWallet/server.js. Agnostico de
 * TenantContext: recibe toda la configuracion por constructor para poder
 * usarse igual desde HTTP y desde scripts CLI.
 */
final class GoogleWalletService implements WalletMessenger {
    public const API_BASE = 'https://walletobjects.googleapis.com/walletobjects/v1';
    public const SAVE_URL_BASE = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly GoogleWalletConfig $config,
        private readonly GoogleServiceAccount $account,
        private readonly GoogleOAuthTokenProvider $tokens,
        private readonly GoogleWalletHttpClient $http
    ) {}

    /** @return array{saveUrl: string, objectId: string, classId: string} */
    public function buildSaveUrl(string $accountId, string $accountName, int $points, ?string $catalogUrl = null): array {
        $objectId = $this->config->objectId($accountId);

        $claims = [
            'iss' => $this->account->clientEmail(),
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'loyaltyClasses' => [$this->loyaltyClassBody()],
                'loyaltyObjects' => [$this->loyaltyObjectBody($objectId, $accountId, $accountName, $points, $catalogUrl)],
            ],
        ];

        $origins = $this->config->origins();
        if ($origins !== []) {
            $claims['origins'] = $origins;
        }

        $token = JWT::encode($claims, $this->account->privateKey(), 'RS256');

        return [
            'saveUrl' => self::SAVE_URL_BASE . $token,
            'objectId' => $objectId,
            'classId' => $this->config->classId(),
        ];
    }

    /**
     * Actualiza el balance en Google (el telefono se sincroniza solo).
     * Si el objeto no existe (404) lo crea completo.
     *
     * @return array{objectId: string, created: bool}
     */
    public function pushPoints(string $accountId, string $accountName, int $points, ?string $catalogUrl = null): array {
        $objectId = $this->config->objectId($accountId);

        return $this->pushPointsToObject($objectId, $accountId, $accountName, $points, $catalogUrl);
    }

    /**
     * Actualiza un objeto concreto ya existente. Se usa para pases legacy
     * importados desde generadorCardWallet, cuyo objectId no sigue el formato
     * canonico nuevo del backend.
     *
     * @return array{objectId: string, created: bool}
     */
    public function pushPointsToObject(string $objectId, string $accountId, string $accountName, int $points, ?string $catalogUrl = null): array {
        $patchBody = [
            'loyaltyPoints' => $this->loyaltyPointsBody($points),
            'linksModuleData' => $this->linksModuleData($catalogUrl),
        ];

        $patch = $this->authorizedRequest('PATCH', '/loyaltyObject/' . rawurlencode($objectId), $patchBody, allow404: true);

        if ($patch['status'] !== 404) {
            return ['objectId' => $objectId, 'created' => false];
        }

        $this->authorizedRequest('POST', '/loyaltyObject', $this->loyaltyObjectBody($objectId, $accountId, $accountName, $points, $catalogUrl));

        return ['objectId' => $objectId, 'created' => true];
    }

    /** @return array<string, mixed> */
    public function getObject(string $objectId): array {
        $response = $this->authorizedRequest('GET', '/loyaltyObject/' . rawurlencode($objectId), null, allow404: true);
        if ($response['status'] === 404) {
            throw new \RuntimeException('El pase no existe en Google Wallet.');
        }

        return is_array($response['json']) ? $response['json'] : [];
    }

    public function pointsFromObject(string $objectId): int {
        $object = $this->getObject($objectId);
        $balance = $object['loyaltyPoints']['balance'] ?? [];
        if (isset($balance['int'])) {
            return (int)$balance['int'];
        }
        if (isset($balance['string'])) {
            return (int)$balance['string'];
        }

        return 0;
    }

    /**
     * Adjunta un mensaje al pase con notificacion push real en el telefono.
     *
     * @return array{objectId: string, messageId: string}
     * @throws \RuntimeException si el pase aun no existe en Google (404)
     */
    public function addMessage(string $accountId, string $header, string $body): array {
        return $this->addMessageToObject($this->config->objectId($accountId), $header, $body);
    }

    /**
     * @return array{objectId: string, messageId: string, messageType?: string}
     */
    public function addMessageToObject(string $objectId, string $header, string $body): array {
        $object = $this->getObject($objectId);
        if (($object['hasUsers'] ?? false) !== true) {
            throw new \RuntimeException('La tarjeta existe en Google Wallet, pero todavia no esta guardada en ningun telefono.');
        }

        $messageId = 'msg_' . bin2hex(random_bytes(6));

        $response = $this->authorizedRequest('POST', '/loyaltyObject/' . rawurlencode($objectId) . '/addMessage', [
            'message' => [
                'id' => $messageId,
                'header' => $header,
                'body' => $body,
                'messageType' => 'TEXT_AND_NOTIFY',
            ],
        ], allow404: true);

        if ($response['status'] === 404) {
            throw new \RuntimeException('El pase aun no existe en Google. El socio debe agregar primero la tarjeta a su Wallet.');
        }

        $messageType = $this->messageTypeFromResource($response['json']['resource'] ?? $response['json'] ?? [], $messageId);
        if ($messageType === null) {
            $messageType = $this->messageTypeFromResource($this->getObject($objectId), $messageId);
        }
        if ($messageType === null || !$this->isNotifyMessageType($messageType)) {
            throw new \RuntimeException('Google Wallet guardo el mensaje sin notificacion push. Revisa el limite de 3 notificaciones por tarjeta cada 24 horas o el throttling de Google.');
        }

        return ['objectId' => $objectId, 'messageId' => $messageId, 'messageType' => $messageType];
    }

    public function objectId(string $accountId): string {
        return $this->config->objectId($accountId);
    }

    public function ownsObjectId(string $objectId): bool {
        return str_starts_with($objectId, $this->config->issuerId() . '.');
    }

    private function messageTypeFromResource(array $resource, string $messageId): ?string {
        foreach (($resource['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['id'] ?? null) === $messageId) {
                return is_string($message['messageType'] ?? null) ? $message['messageType'] : null;
            }
        }

        return null;
    }

    private function isNotifyMessageType(string $messageType): bool {
        return strtolower(str_replace('_', '', $messageType)) === 'textandnotify';
    }

    private function loyaltyClassBody(): array {
        return [
            'id' => $this->config->classId(),
            'issuerName' => $this->config->issuerName(),
            'programName' => $this->config->programName(),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => $this->config->hexBackgroundColor(),
            'programLogo' => ['sourceUri' => ['uri' => $this->config->logoUrl()]],
        ];
    }

    private function loyaltyObjectBody(string $objectId, string $accountId, string $accountName, int $points, ?string $catalogUrl = null): array {
        $body = [
            'id' => $objectId,
            'classId' => $this->config->classId(),
            'state' => 'ACTIVE',
            'accountName' => $accountName,
            'accountId' => $accountId,
            'loyaltyPoints' => $this->loyaltyPointsBody($points),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $accountId,
                'alternateText' => $accountId,
            ],
        ];
        if ($catalogUrl !== null && trim($catalogUrl) !== '') {
            $body['linksModuleData'] = $this->linksModuleData($catalogUrl);
        }

        return $body;
    }

    private function loyaltyPointsBody(int $points): array {
        return [
            'label' => $this->config->pointsLabel(),
            'balance' => ['string' => (string)$points],
        ];
    }

    private function linksModuleData(?string $catalogUrl): array {
        $catalogUrl = trim((string)$catalogUrl);
        if ($catalogUrl === '') {
            return ['uris' => []];
        }

        return [
            'uris' => [[
                'uri' => $catalogUrl,
                'description' => 'Catalogo',
            ]],
        ];
    }

    /**
     * Peticion autenticada a la API de Wallet. HTTP >= 400 lanza
     * GoogleWalletException salvo 404 cuando allow404 es true.
     *
     * @return array{status: int, json: ?array, raw: string}
     */
    private function authorizedRequest(string $method, string $path, ?array $body, bool $allow404 = false): array {
        $headers = ['Authorization: Bearer ' . $this->tokens->accessToken()];
        $encoded = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->http->request($method, self::API_BASE . $path, $headers, $encoded);

        if ($response['status'] >= 400 && !($allow404 && $response['status'] === 404)) {
            throw GoogleWalletException::api($response['status'], $response['json']);
        }

        return $response;
    }
}
