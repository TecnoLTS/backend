<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\IdentityPlatform\Application\TenantRuntimeMutationPolicy;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;

$base = [
    'id' => 'policy-probe',
    'slug' => 'policy-probe',
    'status' => 'active',
    'domains' => ['old.policy.invalid'],
    'enabled_modules' => ['dashboard', 'users'],
];
$at = '2026-07-15T12:00:00+00:00';

$suspended = TenantRuntimeMutationPolicy::transition($base, 'suspend', 'Incidente de seguridad confirmado', 'platform-admin', $at);
if (($suspended['status'] ?? null) !== 'suspended'
    || ($suspended['provisioning_status'] ?? null) !== 'pending_gateway'
    || ($suspended['lifecycle']['lastAction'] ?? null) !== 'suspend') {
    throw new RuntimeException('Suspend did not produce fail-closed desired state.');
}

$resumed = TenantRuntimeMutationPolicy::transition($suspended, 'resume', 'Incidente resuelto y validado', 'platform-admin', $at);
if (($resumed['status'] ?? null) !== 'active' || ($resumed['lifecycle']['lastAction'] ?? null) !== 'resume') {
    throw new RuntimeException('Resume did not restore active desired state.');
}

$offboarded = TenantRuntimeMutationPolicy::transition($resumed, 'offboard', 'Contrato terminado por el cliente', 'platform-admin', $at);
if (($offboarded['status'] ?? null) !== 'inactive'
    || empty($offboarded['offboarded_at'])
    || empty($offboarded['retention_until'])) {
    throw new RuntimeException('Offboard did not preserve an auditable retention tombstone.');
}

try {
    TenantRuntimeMutationPolicy::transition($offboarded, 'resume', 'Intento de reapertura directa', 'platform-admin', $at);
    throw new RuntimeException('Offboarded tenant resumed without explicit rollback.');
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'TENANT_LIFECYCLE_TRANSITION_INVALID') {
        throw $exception;
    }
}

$domainChanged = TenantRuntimeMutationPolicy::updateDomains(
    $base,
    ['new.policy.invalid', 'alias.policy.invalid'],
    'Migracion de dominio aprobada',
    'platform-admin',
    $at
);
if (($domainChanged['domains'] ?? null) !== ['new.policy.invalid', 'alias.policy.invalid']
    || ($domainChanged['domain_change']['previousDomains'] ?? null) !== ['old.policy.invalid']
    || ($domainChanged['provisioning_status'] ?? null) !== 'pending_gateway') {
    throw new RuntimeException('Domain change did not retain rollback evidence/reconciliation state.');
}

foreach ([
    ['domains' => [], 'code' => 'TENANT_DOMAINS_INVALID'],
    ['domains' => ['duplicate.policy.invalid', 'duplicate.policy.invalid'], 'code' => 'TENANT_DOMAINS_INVALID'],
    ['domains' => ['not-a-host'], 'code' => 'TENANT_DOMAINS_INVALID'],
] as $invalid) {
    try {
        TenantRuntimeMutationPolicy::updateDomains($base, $invalid['domains'], 'Cambio administrativo valido', 'platform-admin', $at);
        throw new RuntimeException('Invalid domains were accepted.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== $invalid['code']) {
            throw $exception;
        }
    }
}

$sparseConfigured = [
    'sparse-probe' => [
        'id' => 'sparse-probe',
        'slug' => 'sparse-probe',
        'status' => 'active',
        'domains' => ['sparse.policy.invalid', 'alias.sparse.policy.invalid'],
        'enabled_modules' => ['users', 'dashboard'],
    ],
];
$sparseDesiredHash = TenantRuntimeRegistry::desiredStateHash([
    'id' => 'sparse-probe',
    'slug' => 'sparse-probe',
    'status' => 'active',
    'domains' => ['sparse.policy.invalid', 'alias.sparse.policy.invalid'],
    'enabledModules' => ['dashboard', 'users'],
]);
$sparseExport = TenantRuntimeRegistry::exportWithOverrides($sparseConfigured, [
    'version' => 1,
    'tenants' => [
        'sparse-probe' => [
            'id' => 'sparse-probe',
            'slug' => 'sparse-probe',
            'provisioning_status' => 'ready',
            'provisioning_desired_hash' => $sparseDesiredHash,
        ],
    ],
]);
$sparseTenant = $sparseExport['tenants'][0] ?? [];
if (($sparseTenant['domains'] ?? null) !== ['sparse.policy.invalid', 'alias.sparse.policy.invalid']
    || ($sparseTenant['enabledModules'] ?? null) !== ['dashboard', 'users']
    || ($sparseTenant['provisioningStatus'] ?? null) !== 'ready'
    || ($sparseTenant['businessReady'] ?? null) !== true) {
    throw new RuntimeException('Sparse registry override lost configured identity/readiness.');
}

echo "Tenant runtime lifecycle policy: OK\n";
