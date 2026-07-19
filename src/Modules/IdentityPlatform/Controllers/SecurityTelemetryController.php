<?php

namespace App\Modules\IdentityPlatform\Controllers;

use App\Core\Response;

class SecurityTelemetryController {
    public function cspReport() {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody ?: '{}', true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => substr((string)$rawBody, 0, 4000)];
        }

        $payload = $decoded['csp-report'] ?? $decoded;

        error_log('[CSP_REPORT] ' . json_encode([
            'tenant' => \App\Core\TenantContext::id(),
            'ip' => $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'report' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        Response::json(['received' => true], 200);
    }
}
