<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$routes = file_get_contents($root . '/src/Modules/LoyaltyRewards/routes.php') ?: '';
$index = file_get_contents($root . '/public/index.php') ?: '';
$controller = file_get_contents($root . '/src/Modules/LoyaltyRewards/Controllers/LoyaltyController.php') ?: '';
$repository = file_get_contents($root . '/src/Modules/LoyaltyRewards/Infrastructure/LoyaltyRepository.php') ?: '';
$schema = file_get_contents($root . '/src/Modules/LoyaltyRewards/Infrastructure/LoyaltySchema.php') ?: '';

$checks = [
    'portal limpio GET publico' => str_contains($routes, "'method' => 'GET', 'path' => '/api/l/portal'"),
    'portal limpio claim publico' => str_contains($routes, "'method' => 'POST', 'path' => '/api/l/portal/claims'"),
    'portal limpio cancel publico' => str_contains($routes, "'/api/l/portal/claims/{redemptionId}/cancel'"),
    'exchange token solo GET' => str_contains($routes, "'method' => 'GET', 'path' => '/api/l/r/{token}'")
        && !str_contains($routes, "'method' => 'POST', 'path' => '/api/l/r/{token}"),
    'clasificacion publica portal' => str_contains($index, "str_starts_with(\$uri, '/api/l/portal')"),
    'legacy token no acepta POST publico' => str_contains($index, "\$normalizedMethod === 'GET' && str_starts_with(\$uri, '/api/l/r/')"),
    'csrf reconoce todas las cookies auth' => str_contains($index, 'AuthSurface::authCookieCandidates'),
    'proxy interno no omite csrf autenticado' => !str_contains($index, '&& !$hasTrustedInternalProxyToken'),
    'forms usan nonce claim' => substr_count($controller, 'name="formNonce"') >= 3,
    'nonce se consume condicionalmente' => str_contains($repository, 'AND n.consumed_at IS NULL')
        && str_contains($repository, 'SET consumed_at = NOW()'),
    'nonce guarda solo hash' => str_contains($repository, "'nonce_hash' => hash('sha256', \$nonce)"),
    'tabla de nonce tenantizada' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS loyalty_portal_form_nonces')
        && str_contains($schema, 'tenant_id text NOT NULL'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    fwrite($ok ? STDOUT : STDERR, sprintf("[%s] %s\n", $ok ? 'OK' : 'FAIL', $name));
    if (!$ok) {
        $failed[] = $name;
    }
}

exit($failed === [] ? 0 : 1);
