<?php

namespace App\Modules\LoyaltyRewards\Domain;

/**
 * Catalogo versionado de navegacion que Loyalty publica al dashboard.
 *
 * Las rutas siguen compiladas en Angular; esta clase solo permite resolver
 * route keys conocidas y evita convertir filas de base de datos en rutas
 * arbitrarias.
 */
final class LoyaltyNavigationCatalog {
    public const VERSION = 'fidepuntos-rbac-v1';
    public const INITIAL_TENANT_ID = 'fidepuntos';

    public const ACTION_KEYS = [
        'view',
        'create',
        'update',
        'delete',
        'reverse',
        'approve',
        'deliver',
        'cancel',
        'export',
        'assign_roles',
        'unlock',
        'invite',
        'revoke_sessions',
        'adjust_points',
    ];

    /**
     * @return array<string, array{route: string, namespace: string}>
     */
    public static function routeRegistry(): array {
        return [
            'program-summary' => ['route' => '/loyalty-points', 'namespace' => 'loyalty'],
            'register-purchase' => ['route' => '/loyalty-points/register-purchase', 'namespace' => 'loyalty'],
            'redeem-reward' => ['route' => '/loyalty-points/rewards/redeem', 'namespace' => 'loyalty'],
            'customers' => ['route' => '/loyalty-points/customers', 'namespace' => 'loyalty'],
            'customer-card' => ['route' => '/loyalty-points/customer-card', 'namespace' => 'loyalty'],
            'notifications' => ['route' => '/loyalty-points/notifications', 'namespace' => 'loyalty'],
            'rewards' => ['route' => '/loyalty-points/rewards', 'namespace' => 'loyalty'],
            'redemption-claims' => ['route' => '/loyalty-points/redemptions', 'namespace' => 'loyalty'],
            'rules' => ['route' => '/loyalty-points/rules', 'namespace' => 'loyalty'],
            'settings' => ['route' => '/loyalty-points/settings', 'namespace' => 'loyalty'],
            'reports' => ['route' => '/loyalty-points/reports', 'namespace' => 'loyalty'],
            'report-executive-summary' => ['route' => '/loyalty-points/reports/executive-summary', 'namespace' => 'loyalty'],
            'report-point-activity' => ['route' => '/loyalty-points/reports/point-activity', 'namespace' => 'loyalty'],
            'report-members-tiers' => ['route' => '/loyalty-points/reports/members-tiers', 'namespace' => 'loyalty'],
            'report-card-adoption' => ['route' => '/loyalty-points/reports/card-adoption', 'namespace' => 'loyalty'],
            'report-redemptions-rewards' => ['route' => '/loyalty-points/reports/redemptions-rewards', 'namespace' => 'loyalty'],
            'report-risk-events' => ['route' => '/loyalty-points/reports/risk-events', 'namespace' => 'loyalty'],
            'report-audit-events' => ['route' => '/loyalty-points/reports/audit-events', 'namespace' => 'loyalty'],
            'report-api-usage' => ['route' => '/loyalty-points/reports/api-usage', 'namespace' => 'loyalty'],
            'report-ledger-reconciliation' => ['route' => '/loyalty-points/reports/ledger-reconciliation', 'namespace' => 'loyalty'],
            'users' => ['route' => '/access/users', 'namespace' => 'identity'],
            'roles' => ['route' => '/access/roles', 'namespace' => 'identity'],
            'account-security' => ['route' => '/account/security', 'namespace' => 'identity'],
        ];
    }

    public static function resolveRoute(?string $routeKey): ?string {
        if ($routeKey === null || $routeKey === '') {
            return null;
        }

        return self::routeRegistry()[$routeKey]['route'] ?? null;
    }

    public static function expectedPermissionKey(string $routeKey, string $actionKey): ?string {
        $route = self::routeRegistry()[$routeKey] ?? null;
        if ($route === null || !in_array($actionKey, self::ACTION_KEYS, true)) {
            return null;
        }

        return $route['namespace'] . '.' . $routeKey . '.' . $actionKey;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array {
        return [
            self::section('section.loyalty', 'Fidelizacion', 100),
            self::group('loyalty.root', 'section.loyalty', 'Recompensas y puntos', 'solar:gift-outline', null, 10, 1),
            self::page('loyalty.summary', 'loyalty.root', 'Resumen del programa', 'solar:widget-5-outline', 'program-summary', 10, 2, [
                'view' => 'Ver resumen',
            ]),
            self::group('loyalty.cash', 'loyalty.root', 'Caja y canjes', 'solar:calculator-minimalistic-outline', 'register-purchase', 20, 2),
            self::page('loyalty.purchase', 'loyalty.cash', 'Registrar compra', 'solar:bill-check-outline', 'register-purchase', 10, 3, [
                'view' => 'Ver',
                'create' => 'Registrar compra',
                'reverse' => ['label' => 'Reversar compra', 'dangerous' => true],
            ]),
            self::page('loyalty.redeem', 'loyalty.cash', 'Canjear premio', 'solar:ticket-sale-outline', 'redeem-reward', 20, 3, [
                'view' => 'Ver',
                'create' => 'Registrar canje',
            ]),
            self::group('loyalty.customers-group', 'loyalty.root', 'Clientes y tarjetas', 'solar:users-group-rounded-outline', 'customers', 30, 2),
            self::page('loyalty.customers', 'loyalty.customers-group', 'Administrar clientes', 'solar:user-id-outline', 'customers', 10, 3, [
                'view' => 'Ver clientes',
                'create' => 'Crear cliente',
                'update' => 'Actualizar cliente',
                'adjust_points' => ['label' => 'Ajustar puntos', 'dangerous' => true],
            ]),
            self::page('loyalty.customer-card', 'loyalty.customers-group', 'Emitir tarjeta digital', 'solar:smartphone-outline', 'customer-card', 20, 3, [
                'view' => 'Ver tarjeta',
                'create' => 'Emitir tarjeta',
                'update' => 'Actualizar tarjeta',
            ]),
            self::page('loyalty.notifications', 'loyalty.root', 'Notificaciones', 'solar:bell-outline', 'notifications', 40, 2, [
                'view' => 'Ver campanas',
                'create' => 'Crear campana',
            ]),
            self::group('loyalty.rewards-group', 'loyalty.root', 'Premios', 'solar:gift-outline', 'rewards', 50, 2),
            self::page('loyalty.rewards', 'loyalty.rewards-group', 'Gestionar premios', 'solar:tag-price-outline', 'rewards', 10, 3, [
                'view' => 'Ver premios',
                'create' => 'Crear premio',
                'update' => 'Actualizar premio',
                'delete' => ['label' => 'Eliminar premio', 'dangerous' => true],
            ]),
            self::page('loyalty.redemption-claims', 'loyalty.rewards-group', 'Solicitudes de premios', 'solar:checklist-minimalistic-outline', 'redemption-claims', 20, 3, [
                'view' => 'Ver solicitudes',
                'approve' => ['label' => 'Aprobar', 'dangerous' => true],
                'deliver' => ['label' => 'Entregar', 'dangerous' => true],
                'cancel' => ['label' => 'Cancelar', 'dangerous' => true],
            ]),
            self::group('loyalty.configuration', 'loyalty.root', 'Reglas y configuracion', 'solar:tuning-2-outline', 'rules', 60, 2),
            self::page('loyalty.rules', 'loyalty.configuration', 'Reglas del programa', 'solar:shield-check-outline', 'rules', 10, 3, [
                'view' => 'Ver reglas',
                'update' => 'Actualizar reglas',
            ]),
            self::page('loyalty.settings', 'loyalty.configuration', 'Configuracion general', 'solar:settings-outline', 'settings', 20, 3, [
                'view' => 'Ver configuracion',
                'create' => 'Crear credencial API',
                'update' => 'Actualizar configuracion',
            ]),
            self::group('loyalty.reports', 'loyalty.root', 'Reportes', 'solar:chart-2-outline', 'reports', 70, 2, [
                'view' => 'Ver catalogo de reportes',
            ]),
            self::report('executive-summary', 'Resumen ejecutivo', 'solar:chart-square-outline', 10),
            self::report('point-activity', 'Actividad de puntos', 'solar:pulse-2-outline', 20),
            self::report('members-tiers', 'Socios y niveles', 'solar:ranking-outline', 30),
            self::report('card-adoption', 'Tarjetas digitales', 'solar:smartphone-2-outline', 40),
            self::report('redemptions-rewards', 'Canjes y premios', 'solar:ticket-sale-outline', 50),
            self::report('risk-events', 'Riesgo y antifraude', 'solar:shield-warning-outline', 60, [
                'update' => ['label' => 'Resolver riesgo', 'dangerous' => true],
            ]),
            self::report('audit-events', 'Auditoria', 'solar:checklist-minimalistic-outline', 70),
            self::report('api-usage', 'Uso de API', 'solar:programming-outline', 80),
            self::report('ledger-reconciliation', 'Conciliacion de saldos', 'solar:wallet-money-outline', 90),

            self::section('section.access', 'Administracion', 200),
            self::group('identity.root', 'section.access', 'Usuarios y roles', 'solar:users-group-rounded-outline', null, 10, 1),
            self::page('identity.users', 'identity.root', 'Usuarios', 'solar:users-group-rounded-outline', 'users', 10, 2, [
                'view' => 'Ver usuarios',
                'create' => 'Invitar usuario',
                'update' => 'Actualizar usuario',
                'assign_roles' => ['label' => 'Asignar roles', 'dangerous' => true],
                'unlock' => ['label' => 'Desbloquear', 'dangerous' => true],
                'invite' => ['label' => 'Enviar invitacion', 'dangerous' => true],
                'revoke_sessions' => ['label' => 'Revocar sesiones', 'dangerous' => true],
            ]),
            self::page('identity.roles', 'identity.root', 'Roles y permisos', 'solar:shield-user-outline', 'roles', 20, 2, [
                'view' => 'Ver roles',
                'create' => 'Crear rol',
                'update' => 'Actualizar rol',
                'delete' => ['label' => 'Eliminar rol', 'dangerous' => true],
                'assign_roles' => ['label' => 'Asignar opciones', 'dangerous' => true],
            ]),

            self::section('section.account', 'Cuenta', 300),
            self::page('identity.account-security', 'section.account', 'Mi perfil y seguridad', 'solar:shield-keyhole-outline', 'account-security', 10, 1, [
                'view' => 'Ver seguridad',
                'update' => ['label' => 'Cambiar contrasena', 'dangerous' => true],
                'revoke_sessions' => ['label' => 'Cerrar otras sesiones', 'dangerous' => true],
            ], true),
        ];
    }

    private static function section(string $key, string $label, int $sortOrder): array {
        return self::definition($key, null, 'section', $label, null, null, $sortOrder, 0, [], false);
    }

    private static function group(
        string $key,
        string $parentKey,
        string $label,
        string $icon,
        ?string $routeKey,
        int $sortOrder,
        int $depth,
        array $actions = []
    ): array {
        return self::definition($key, $parentKey, 'group', $label, $icon, $routeKey, $sortOrder, $depth, $actions, false);
    }

    private static function page(
        string $key,
        string $parentKey,
        string $label,
        string $icon,
        string $routeKey,
        int $sortOrder,
        int $depth,
        array $actions,
        bool $mandatory = false
    ): array {
        return self::definition($key, $parentKey, 'page', $label, $icon, $routeKey, $sortOrder, $depth, $actions, $mandatory);
    }

    private static function report(string $reportKey, string $label, string $icon, int $sortOrder, array $extraActions = []): array {
        return self::page(
            'loyalty.report.' . $reportKey,
            'loyalty.reports',
            $label,
            $icon,
            'report-' . $reportKey,
            $sortOrder,
            3,
            array_merge([
                'view' => 'Ver reporte',
                'export' => 'Exportar reporte',
            ], $extraActions)
        );
    }

    private static function definition(
        string $key,
        ?string $parentKey,
        string $kind,
        string $label,
        ?string $icon,
        ?string $routeKey,
        int $sortOrder,
        int $depth,
        array $actions,
        bool $mandatory
    ): array {
        $normalizedActions = [];
        foreach ($actions as $actionKey => $definition) {
            $definition = is_array($definition) ? $definition : ['label' => $definition];
            $permissionKey = $routeKey !== null
                ? self::expectedPermissionKey($routeKey, (string)$actionKey)
                : null;
            if ($permissionKey === null) {
                throw new \LogicException("Accion o route key invalida en el catalogo: {$key}.{$actionKey}");
            }
            $normalizedActions[] = [
                'key' => (string)$actionKey,
                'permissionKey' => $permissionKey,
                'label' => (string)($definition['label'] ?? $actionKey),
                'dangerous' => (bool)($definition['dangerous'] ?? false),
            ];
        }

        return [
            'key' => $key,
            'parentKey' => $parentKey,
            'kind' => $kind,
            'label' => $label,
            'icon' => $icon,
            'routeKey' => $routeKey,
            'sortOrder' => $sortOrder,
            'depth' => $depth,
            'mandatory' => $mandatory,
            'actions' => $normalizedActions,
        ];
    }
}
