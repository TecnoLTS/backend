<?php

declare(strict_types=1);

$repositoryPath = dirname(__DIR__) . '/src/Repositories/UserRepository.php';
$workspace = dirname(__DIR__, 2);
$isolationPath = $workspace . '/basesdedatos/scripts/tenant-isolation.sh';
$capabilityPath = $workspace . '/basesdedatos/scripts/platform-auth-capability.sql';
$runtimeCheckPath = $workspace . '/basesdedatos/scripts/check-platform-auth-capability.sql';

$repository = file_get_contents($repositoryPath);
$isolation = file_get_contents($isolationPath);
$capability = file_get_contents($capabilityPath);
$runtimeCheck = file_get_contents($runtimeCheckPath);
if (!is_string($repository) || !is_string($isolation) || !is_string($capability) || !is_string($runtimeCheck)) {
    fwrite(STDERR, "No se pudieron leer todos los contratos platform-auth/RLS.\n");
    exit(1);
}

/** @return string */
function methodBody(string $source, string $method): string
{
    if (preg_match('/(?:public|private|protected) function ' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException("No se encontro el metodo {$method}.");
    }
    $start = $match[0][1] + strlen($match[0][0]);
    $depth = 1;
    $length = strlen($source);
    for ($offset = $start; $offset < $length; $offset++) {
        if ($source[$offset] === '{') {
            $depth++;
        } elseif ($source[$offset] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $offset - $start);
            }
        }
    }
    throw new RuntimeException("El metodo {$method} no tiene cierre balanceado.");
}

$methods = [
    'updatePassword' => 'update_password',
    'resetPasswordAfterRecovery' => 'reset_password',
    'setOtpForEmail' => 'set_otp',
    'markEmailVerifiedByOtp' => 'verify_otp',
    'incrementOtpAttempts' => 'increment_otp_attempts',
    'setLoginFailureState' => 'set_login_failure',
    'clearLoginFailures' => 'clear_login_failures',
    'markSuccessfulLogin' => 'mark_successful_login',
    'setActiveTokenId' => 'set_active_token',
    'clearActiveTokenId' => 'clear_active_token',
    'clearActiveTokenIdIfMatches' => 'clear_active_token',
];

foreach ($methods as $method => $operation) {
    $body = methodBody($repository, $method);
    $execute = strpos($body, '$stmt->execute(');
    $fallback = strpos($body, "mutatePlatformAuthentication('{$operation}'");
    if (!str_contains($body, 'tenant_id = :tenant_id')
        || str_contains($body, 'platform_tenant_id')
        || $execute === false
        || $fallback === false
        || $execute >= $fallback
        || !str_contains($body, '$stmt->rowCount() === 0')) {
        fwrite(STDERR, "Mutacion {$method} no conserva tenant-first + platform capability fallback.\n");
        exit(1);
    }
}

$readMethods = [
    'getByEmail' => 'lookup_login',
    'getByEmailWithOtp' => 'lookup_login_otp',
    'getById' => 'get_identity',
    'getAuthState' => 'get_auth_state',
    'getPasswordHash' => 'get_password_hash',
    'getActiveTokenId' => 'get_auth_state',
];
foreach ($readMethods as $method => $operation) {
    $body = methodBody($repository, $method);
    $execute = strpos($body, '$stmt->execute(');
    $fallback = strpos($body, "readPlatformAuthentication('{$operation}'");
    if (!str_contains($body, 'tenant_id = :tenant_id')
        || str_contains($body, 'platform_tenant_id')
        || $execute === false
        || $fallback === false
        || $execute >= $fallback) {
        fwrite(STDERR, "Lectura {$method} no conserva tenant-first + platform capability fallback.\n");
        exit(1);
    }
}

$otpBody = methodBody($repository, 'setOtpForEmail');
if (!str_contains($otpBody, 'LOWER(email) = LOWER(:email) AND tenant_id = :tenant_id')) {
    fwrite(STDERR, "setOtpForEmail no prioriza de forma estricta el usuario del tenant.\n");
    exit(1);
}

$helper = methodBody($repository, 'mutatePlatformAuthentication');
foreach (array_unique(array_values($methods)) as $operation) {
    if (!str_contains($helper, "'{$operation}' => 'SELECT platform_auth.{$operation}")) {
        fwrite(STDERR, "Falta la llamada tipada platform-auth para {$operation}.\n");
        exit(1);
    }
}

$readHelper = methodBody($repository, 'readPlatformAuthentication');
foreach ([
    'lookup_login' => 'SELECT platform_auth.lookup_login_candidate(:email, FALSE)',
    'lookup_login_otp' => 'SELECT platform_auth.lookup_login_candidate(:email, TRUE)',
    'get_identity' => 'SELECT platform_auth.get_identity(:id)',
    'get_auth_state' => 'SELECT platform_auth.get_auth_state(:id)',
    'get_password_hash' => 'SELECT platform_auth.get_password_hash(:id)',
] as $operation => $call) {
    if (!str_contains($readHelper, "'{$operation}' => '{$call}'")) {
        fwrite(STDERR, "Falta la lectura tipada platform-auth para {$operation}.\n");
        exit(1);
    }
}
foreach ([
    'lectura solo User canonico' => 'if ($this->userTable !== \'"User"\')',
    'lectura capability con RLS' => '$rlsMode !== \'enforce\'',
    'fallback platform parametrizado' => "'platform_tenant_id' => self::PLATFORM_TENANT_ID",
] as $description => $snippet) {
    if (!str_contains($readHelper, $snippet)) {
        fwrite(STDERR, "Falta contrato de lectura repository: {$description}.\n");
        exit(1);
    }
}
foreach ([
    'solo User canonico' => 'if ($this->userTable !== \'"User"\')',
    'capability solo con RLS' => '$rlsMode === \'enforce\'',
    'fallback platform explicito' => "tenant_id = \\'platform\\'",
] as $description => $snippet) {
    if (!str_contains($helper, $snippet)) {
        fwrite(STDERR, "Falta contrato repository: {$description}.\n");
        exit(1);
    }
}

$requiredIsolation = [
    'rol dedicado' => 'DB_PLATFORM_AUTH_ROLE',
    'rol NOLOGIN BYPASSRLS' => 'NOLOGIN NOINHERIT NOSUPERUSER NOCREATEROLE NOCREATEDB NOREPLICATION BYPASSRLS',
    'policy User excluye platform' => "tenant_id <> ''platform''",
    'instalacion capability' => 'platform-auth-capability.sql',
    'auditor canonico' => 'check-platform-auth-capability.sql',
];
foreach ($requiredIsolation as $description => $snippet) {
    if (!str_contains($isolation, $snippet)) {
        fwrite(STDERR, "Falta contrato tenant-isolation: {$description}.\n");
        exit(1);
    }
}

foreach ([
    'SECURITY DEFINER',
    'SET search_path = pg_catalog',
    "WHERE tenant_id = 'platform'",
    'REVOKE ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA platform_auth FROM PUBLIC',
    'REVOKE ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA platform_auth FROM :"app_role", :"worker_role"',
    'GRANT USAGE ON SCHEMA public TO :"platform_auth_role"',
    'REVOKE CREATE ON SCHEMA public FROM :"platform_auth_role"',
    'GRANT SELECT (',
    'platform_auth.lookup_login_candidate(text, boolean)',
    'platform_auth.get_identity(text)',
    'platform_auth.get_auth_state(text)',
    'platform_auth.get_password_hash(text)',
    'platform_auth.get_tenant_runtime_registry()',
    'platform_auth.get_tenant_runtime_registry_state()',
    'platform_auth.set_tenant_runtime_registry(jsonb, bigint, text, text, text, text, text, text)',
    "value IS JSON OBJECT",
    "count(*) FROM jsonb_object_keys(p_payload->'tenants')",
] as $snippet) {
    if (!str_contains($capability, $snippet)) {
        fwrite(STDERR, "Falta limite SQL platform-auth: {$snippet}.\n");
        exit(1);
    }
}

foreach ([
    "SELECT set_config('app.tenant_id', 'platform', true)",
    'API role directly updated a platform row under FORCE RLS',
    'API role directly read a platform row under FORCE RLS',
    'Typed platform-auth readers did not return their platform target',
    'Typed platform-auth reader crossed into a tenant row',
    'Typed platform-auth capability crossed into a tenant row',
    "has_function_privilege('public'",
    "has_schema_privilege(current_setting('app.platform_auth_role'), 'public', 'USAGE')",
    'zero memberships in either direction',
    'Tenant registry capability did not preserve the previous desired state',
    'Tenant registry capability modified a tenant-scoped Setting row',
    "platform_after - ARRAY['failed_login_attempts', 'login_locked_until', 'otp_attempts', 'updated_at']",
] as $snippet) {
    if (!str_contains($runtimeCheck, $snippet)) {
        fwrite(STDERR, "Falta prueba runtime platform-auth: {$snippet}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Contrato platform-auth bajo FORCE RLS: OK\n");
