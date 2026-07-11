<?php

namespace App\Modules\LoyaltyRewards\Application;

use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyNavigationRepository;

final class LoyaltyNavigationService {
    /** Permiso imposible usado para fallar cerrado ante una ruta admin no mapeada. */
    public const DENY_PERMISSION = 'loyalty.__unmapped__.deny';

    public function __construct(private readonly ?LoyaltyNavigationRepository $repository = null) {}

    /**
     * Catalogo completo para el editor de roles.
     *
     * @return array{version: string, sections: list<array<string, mixed>>, options: list<array<string, mixed>>}
     */
    public function catalog(string $tenantId): array {
        $tenantId = $this->normalizeTenantId($tenantId);
        if ($tenantId !== LoyaltyNavigationCatalog::INITIAL_TENANT_ID) {
            return $this->emptyCatalog();
        }

        $repository = $this->repository ?? new LoyaltyNavigationRepository();
        $rawItems = $repository->activeItems($tenantId);
        $rawActions = $repository->activeActions($tenantId);

        return $this->buildCatalog($rawItems, $rawActions);
    }

    /**
     * Arbol que puede mostrar el dashboard para la union de permisos de un usuario.
     * Los padres sin hijos visibles desaparecen y las opciones obligatorias se
     * conservan aun cuando el servicio central de permisos falle cerrado.
     *
     * @param list<string> $permissions
     * @return array{version: string, sections: list<array<string, mixed>>, options: list<array<string, mixed>>}
     */
    public function effectiveNavigation(string $tenantId, array $permissions): array {
        $catalog = $this->catalog($tenantId);
        $permissionSet = $this->permissionSetWithImpliedViews($catalog, $permissions);
        $sections = [];

        foreach ($catalog['sections'] as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $visible = $this->filterEffectiveItem($item, $permissionSet);
                if ($visible !== null) {
                    $items[] = $visible;
                }
            }
            if ($items !== []) {
                $sections[] = [
                    'key' => $section['key'],
                    'title' => $section['title'],
                    'items' => $items,
                ];
            }
        }

        return [
            'version' => $catalog['version'],
            'sections' => $sections,
            'options' => $this->flattenSections($sections),
        ];
    }

    /**
     * Normaliza una seleccion proveniente del editor de roles. Solo admite
     * permisos publicados por este catalogo y agrega view cuando se selecciona
     * cualquier otra accion de la misma opcion.
     *
     * @param list<string> $permissions
     * @return list<string>
     */
    public function normalizeGrantPermissions(string $tenantId, array $permissions): array {
        $catalog = $this->catalog($tenantId);
        $knownPermissions = [];
        foreach ($catalog['options'] as $option) {
            foreach ($option['actions'] as $action) {
                $knownPermissions[(string)$action['permissionKey']] = true;
            }
        }

        $normalized = [];
        foreach ($permissions as $permission) {
            $permission = trim((string)$permission);
            if ($permission === '') {
                continue;
            }
            if (!isset($knownPermissions[$permission])) {
                throw new \InvalidArgumentException("Permiso de navegacion no publicado: {$permission}");
            }
            $normalized[$permission] = true;
        }

        return array_keys($this->permissionSetWithImpliedViews($catalog, array_keys($normalized)));
    }

    /**
     * Traduce el contrato HTTP admin de Loyalty al mismo permiso granular que
     * usa el menu. Retorna null para superficies publicas/externas y un permiso
     * imposible para cualquier ruta /api/admin/loyalty no reconocida.
     */
    public function requiredPermissionForRequest(string $method, string $uri): ?string {
        $method = strtoupper(trim($method));
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
        $prefixPosition = strpos($path, '/api/admin/loyalty');
        if ($prefixPosition === false) {
            return null;
        }
        $path = substr($path, $prefixPosition);
        if (!str_starts_with($path, '/api/admin/loyalty')) {
            return null;
        }

        if ($method === 'GET' && $path === '/api/admin/loyalty/navigation/catalog') {
            return $this->permission('roles', 'view');
        }
        if ($method === 'GET' && $path === '/api/admin/loyalty/dashboard') {
            return $this->permission('program-summary', 'view');
        }

        if ($method === 'GET' && preg_match('#^/api/admin/loyalty/reports/([^/]+)(/(?:export|catalog))?/?$#', $path, $matches) === 1) {
            $routeKey = 'report-' . strtolower((string)$matches[1]);
            $action = ($matches[2] ?? '') === '/export' ? 'export' : 'view';
            return $this->permission($routeKey, $action) ?? self::DENY_PERMISSION;
        }
        if ($method === 'GET' && rtrim($path, '/') === '/api/admin/loyalty/reports') {
            return $this->permission('reports', 'view');
        }

        $rules = [
            ['GET', '#^/api/admin/loyalty/dashboard/customers/?$#', 'program-summary', 'view'],
            ['GET', '#^/api/admin/loyalty/purchases/context/?$#', 'register-purchase', 'view'],
            ['GET', '#^/api/admin/loyalty/redemptions/context/?$#', 'redeem-reward', 'view'],
            ['GET', '#^/api/admin/loyalty/wallet/context/?$#', 'customer-card', 'view'],
            ['GET', '#^/api/admin/loyalty/notifications/context/?$#', 'notifications', 'create'],
            ['GET', '#^/api/admin/loyalty/customers(?:/[^/]+)?/?$#', 'customers', 'view'],
            ['POST', '#^/api/admin/loyalty/customers/?$#', 'customers', 'create'],
            ['PATCH', '#^/api/admin/loyalty/customers/[^/]+/?$#', 'customers', 'update'],
            ['GET', '#^/api/admin/loyalty/rewards(?:/[^/]+)?/?$#', 'rewards', 'view'],
            ['POST', '#^/api/admin/loyalty/rewards/?$#', 'rewards', 'create'],
            ['POST', '#^/api/admin/loyalty/rewards/image/?$#', 'rewards', 'create'],
            ['PATCH', '#^/api/admin/loyalty/rewards/[^/]+/?$#', 'rewards', 'update'],
            ['DELETE', '#^/api/admin/loyalty/rewards/[^/]+/?$#', 'rewards', 'delete'],
            ['POST', '#^/api/admin/loyalty/redemptions/?$#', 'redeem-reward', 'create'],
            ['GET', '#^/api/admin/loyalty/redemption-claims/?$#', 'redemption-claims', 'view'],
            ['POST', '#^/api/admin/loyalty/redemption-claims/validate-code/?$#', 'redemption-claims', 'deliver'],
            ['POST', '#^/api/admin/loyalty/redemption-claims/[^/]+/approve/?$#', 'redemption-claims', 'approve'],
            ['POST', '#^/api/admin/loyalty/redemption-claims/[^/]+/deliver/?$#', 'redemption-claims', 'deliver'],
            ['POST', '#^/api/admin/loyalty/redemption-claims/[^/]+/cancel/?$#', 'redemption-claims', 'cancel'],
            ['POST', '#^/api/admin/loyalty/purchases/?$#', 'register-purchase', 'create'],
            ['POST', '#^/api/admin/loyalty/purchases/[^/]+/reverse/?$#', 'register-purchase', 'reverse'],
            ['POST', '#^/api/admin/loyalty/adjustments/?$#', 'customers', 'update'],
            ['GET', '#^/api/admin/loyalty/settings/?$#', 'settings', 'view'],
            ['PUT', '#^/api/admin/loyalty/settings/?$#', 'settings', 'update'],
            ['GET', '#^/api/admin/loyalty/rules/?$#', 'rules', 'view'],
            ['PUT', '#^/api/admin/loyalty/rules/?$#', 'rules', 'update'],
            ['GET', '#^/api/admin/loyalty/audit-events/?$#', 'report-audit-events', 'view'],
            ['GET', '#^/api/admin/loyalty/risk-events/?$#', 'report-risk-events', 'view'],
            ['PATCH', '#^/api/admin/loyalty/risk-events/[^/]+/resolve/?$#', 'report-risk-events', 'update'],
            ['GET', '#^/api/admin/loyalty/api-clients/?$#', 'settings', 'view'],
            ['POST', '#^/api/admin/loyalty/api-clients/?$#', 'settings', 'create'],
            ['PATCH', '#^/api/admin/loyalty/api-clients/[^/]+/?$#', 'settings', 'update'],
            ['POST', '#^/api/admin/loyalty/api-clients/[^/]+/revoke/?$#', 'settings', 'update'],
            ['POST', '#^/api/admin/loyalty/notifications/preview/?$#', 'notifications', 'create'],
            ['GET', '#^/api/admin/loyalty/notifications(?:/[^/]+)?/?$#', 'notifications', 'view'],
            ['POST', '#^/api/admin/loyalty/notifications/?$#', 'notifications', 'create'],
            ['PATCH', '#^/api/admin/loyalty/members/[^/]+/wallet/?$#', 'customer-card', 'update'],
            ['GET', '#^/api/admin/loyalty/members/[^/]+/pass-preview/?$#', 'customer-card', 'view'],
            ['POST', '#^/api/admin/loyalty/wallet/google-link/?$#', 'customer-card', 'create'],
            ['POST', '#^/api/admin/loyalty/wallet/notify/?$#', 'customer-card', 'update'],
        ];

        foreach ($rules as [$expectedMethod, $pattern, $routeKey, $action]) {
            if ($method === $expectedMethod && preg_match($pattern, $path) === 1) {
                return $this->permission($routeKey, $action) ?? self::DENY_PERMISSION;
            }
        }

        return self::DENY_PERMISSION;
    }

    private function buildCatalog(array $rawItems, array $rawActions): array {
        $candidates = [];
        foreach ($rawItems as $row) {
            $key = trim((string)($row['item_key'] ?? ''));
            $kind = trim((string)($row['item_kind'] ?? ''));
            $label = trim((string)($row['label'] ?? ''));
            $routeKey = $this->nullableString($row['route_key'] ?? null);
            if ($key === '' || $label === '' || !in_array($kind, ['section', 'group', 'page'], true)) {
                $this->warn('item invalido omitido', $key);
                continue;
            }
            if (($kind === 'section' && $routeKey !== null) || ($kind === 'page' && $routeKey === null)) {
                $this->warn('forma de item invalida omitida', $key);
                continue;
            }
            if ($routeKey !== null && LoyaltyNavigationCatalog::resolveRoute($routeKey) === null) {
                $this->warn('route key desconocida omitida', $key);
                continue;
            }

            $candidates[$key] = [
                'key' => $key,
                'parentKey' => $this->nullableString($row['parent_item_key'] ?? null),
                'kind' => $kind,
                'label' => $label,
                'icon' => $this->nullableString($row['icon'] ?? null),
                'routeKey' => $routeKey,
                'route' => LoyaltyNavigationCatalog::resolveRoute($routeKey),
                'sortOrder' => (int)($row['sort_order'] ?? 0),
                'storedDepth' => (int)($row['depth'] ?? -1),
                'mandatory' => $key === 'identity.account-security'
                    && $this->databaseBoolean($row['mandatory'] ?? false),
                'actions' => [],
            ];
        }

        $depthMemo = [];
        $valid = [];
        foreach (array_keys($candidates) as $key) {
            $depth = $this->resolveDepth($key, $candidates, $depthMemo, []);
            if ($depth === null || $depth !== $candidates[$key]['storedDepth']) {
                $this->warn('jerarquia invalida omitida', $key);
                continue;
            }
            $valid[$key] = $candidates[$key];
            unset($valid[$key]['storedDepth']);
        }

        foreach ($rawActions as $row) {
            $itemKey = trim((string)($row['item_key'] ?? ''));
            $actionKey = trim((string)($row['action_key'] ?? ''));
            $permissionKey = trim((string)($row['permission_key'] ?? ''));
            $item = $valid[$itemKey] ?? null;
            $expectedPermission = $item !== null && $item['routeKey'] !== null
                ? LoyaltyNavigationCatalog::expectedPermissionKey($item['routeKey'], $actionKey)
                : null;
            if ($expectedPermission === null || !hash_equals($expectedPermission, $permissionKey)) {
                $this->warn('accion invalida omitida', $itemKey . ':' . $actionKey);
                continue;
            }
            $valid[$itemKey]['actions'][] = [
                'key' => $actionKey,
                'permissionKey' => $permissionKey,
                'label' => trim((string)($row['label'] ?? $actionKey)),
                'dangerous' => $this->databaseBoolean($row['dangerous'] ?? false),
                '_sortOrder' => (int)($row['sort_order'] ?? 0),
            ];
        }

        foreach ($valid as &$item) {
            usort($item['actions'], static fn(array $left, array $right): int =>
                [$left['_sortOrder'], $left['key']] <=> [$right['_sortOrder'], $right['key']]
            );
            foreach ($item['actions'] as &$action) {
                unset($action['_sortOrder']);
            }
            unset($action);
        }
        unset($item);

        $childrenByParent = [];
        $sections = [];
        foreach ($valid as $item) {
            if ($item['kind'] === 'section') {
                $sections[] = $item;
                continue;
            }
            $childrenByParent[$item['parentKey']][] = $item['key'];
        }
        usort($sections, fn(array $left, array $right): int => $this->sortItems($left, $right));
        foreach ($childrenByParent as &$childKeys) {
            usort($childKeys, fn(string $left, string $right): int => $this->sortItems($valid[$left], $valid[$right]));
        }
        unset($childKeys);

        $sectionPayloads = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($childrenByParent[$section['key']] ?? [] as $childKey) {
                $items[] = $this->buildTreeItem($childKey, $valid, $childrenByParent);
            }
            if ($items !== []) {
                $sectionPayloads[] = [
                    'key' => $section['key'],
                    'title' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return [
            'version' => LoyaltyNavigationCatalog::VERSION,
            'sections' => $sectionPayloads,
            'options' => $this->flattenSections($sectionPayloads),
        ];
    }

    private function buildTreeItem(string $key, array $items, array $childrenByParent): array {
        $item = $items[$key];
        $children = [];
        foreach ($childrenByParent[$key] ?? [] as $childKey) {
            $children[] = $this->buildTreeItem($childKey, $items, $childrenByParent);
        }
        if ($children !== []) {
            $item['children'] = $children;
        }

        return $item;
    }

    private function resolveDepth(string $key, array $items, array &$memo, array $stack): ?int {
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        if (isset($stack[$key]) || !isset($items[$key])) {
            return $memo[$key] = null;
        }

        $stack[$key] = true;
        $item = $items[$key];
        if ($item['kind'] === 'section') {
            return $memo[$key] = ($item['parentKey'] === null ? 0 : null);
        }
        if ($item['parentKey'] === null || !isset($items[$item['parentKey']])) {
            return $memo[$key] = null;
        }
        $parent = $items[$item['parentKey']];
        if ($parent['kind'] === 'page') {
            return $memo[$key] = null;
        }

        $parentDepth = $this->resolveDepth($item['parentKey'], $items, $memo, $stack);
        $depth = $parentDepth === null ? null : $parentDepth + 1;
        if ($depth === null || $depth > 3) {
            return $memo[$key] = null;
        }

        return $memo[$key] = $depth;
    }

    private function filterEffectiveItem(array $item, array $permissionSet): ?array {
        $children = [];
        foreach ($item['children'] ?? [] as $child) {
            $visible = $this->filterEffectiveItem($child, $permissionSet);
            if ($visible !== null) {
                $children[] = $visible;
            }
        }

        $actions = array_values(array_filter(
            $item['actions'],
            static fn(array $action): bool => isset($permissionSet[$action['permissionKey']])
        ));
        $hasView = count(array_filter($actions, static fn(array $action): bool => $action['key'] === 'view')) > 0;
        if (!$item['mandatory'] && !$hasView && $children === []) {
            return null;
        }

        // Un grupo estructural puede sobrevivir por sus hijos, pero no debe
        // enlazar a una pantalla cuyo view no fue concedido directamente.
        if (!$hasView) {
            $item['routeKey'] = null;
            $item['route'] = null;
        }

        $item['actions'] = $actions;
        if ($children !== []) {
            $item['children'] = $children;
        } else {
            unset($item['children']);
        }

        return $item;
    }

    private function permissionSetWithImpliedViews(array $catalog, array $permissions): array {
        $set = [];
        foreach ($permissions as $permission) {
            $permission = trim((string)$permission);
            if ($permission !== '') {
                $set[$permission] = true;
            }
        }

        if (isset($set['platform-admin'])) {
            foreach ($catalog['options'] as $option) {
                foreach ($option['actions'] as $action) {
                    $set[$action['permissionKey']] = true;
                }
            }
        }

        foreach ($catalog['options'] as $option) {
            $viewPermission = null;
            $hasNonView = false;
            foreach ($option['actions'] as $action) {
                if ($action['key'] === 'view') {
                    $viewPermission = $action['permissionKey'];
                } elseif (isset($set[$action['permissionKey']])) {
                    $hasNonView = true;
                }
            }
            if ($hasNonView && $viewPermission !== null) {
                $set[$viewPermission] = true;
            }
        }

        return $set;
    }

    private function flattenSections(array $sections): array {
        $flat = [];
        $append = function (array $item) use (&$append, &$flat): void {
            $children = $item['children'] ?? [];
            unset($item['children']);
            $flat[] = $item;
            foreach ($children as $child) {
                $append($child);
            }
        };
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $append($item);
            }
        }

        return $flat;
    }

    private function permission(string $routeKey, string $action): ?string {
        return LoyaltyNavigationCatalog::expectedPermissionKey($routeKey, $action);
    }

    private function sortItems(array $left, array $right): int {
        return [$left['sortOrder'], $left['key']] <=> [$right['sortOrder'], $right['key']];
    }

    private function databaseBoolean(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function nullableString(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normalizeTenantId(string $tenantId): string {
        return strtolower(trim($tenantId));
    }

    private function emptyCatalog(): array {
        return [
            'version' => LoyaltyNavigationCatalog::VERSION,
            'sections' => [],
            'options' => [],
        ];
    }

    private function warn(string $message, string $key): void {
        error_log(sprintf('[LOYALTY_NAVIGATION_WARNING] %s; key=%s', $message, $key !== '' ? $key : 'unknown'));
    }
}
