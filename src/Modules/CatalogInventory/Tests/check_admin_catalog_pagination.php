<?php

declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 3);
$controller = file_get_contents(dirname(__DIR__) . '/Controllers/ProductController.php');
$repository = file_get_contents($sourceRoot . '/Repositories/ProductRepository.php');
$bootstrap = file_get_contents(dirname($sourceRoot) . '/scripts/bootstrap_schema.php');

if (!is_string($controller) || !is_string($repository) || !is_string($bootstrap)) {
    fwrite(STDERR, "No se pudo leer el contrato de catalogo administrativo.\n");
    exit(1);
}

$checks = [
    'admin controller requires bounded page size' => str_contains($controller, 'private function adminCatalogPageSize(): int'),
    'admin controller rejects limits over 100' => str_contains($controller, '$pageSize > 100'),
    'admin controller uses cursor page' => str_contains($controller, '$this->productRepository->getAdminPage('),
    'admin controller publishes pagination metadata' => str_contains($controller, "'nextCursor' => \$nextCursor"),
    'legacy scope=admin reuses bounded admin page' => preg_match(
        '/public function index\(\).*?public function adminIndex\(/s',
        $controller,
        $indexMatch
    ) === 1 && str_contains($indexMatch[0], '$this->respondWithAdminCatalogPage()'),
    'HTTP product handlers never call repository getAll' => !str_contains(
        $controller,
        '$this->productRepository->getAll('
    ),
    'admin controller no longer invokes unbounded getAll' => preg_match(
        '/public function adminIndex\(\).*?public function show\(/s',
        $controller,
        $match
    ) === 1 && !str_contains($match[0], '->getAll('),
    'repository caps page size' => str_contains($repository, '$pageSize = max(1, min(100, $pageSize));'),
    'repository uses limit plus lookahead' => str_contains($repository, "' LIMIT ' . (\$pageSize + 1)"),
    'repository cursor is tenant scoped' => str_contains($repository, "getAdminPage(int \$pageSize")
        && str_contains($repository, "' WHERE p.tenant_id = :tenant_id'"),
    'database has matching admin keyset index' => str_contains($bootstrap, '"Product_admin_cursor_idx"')
        && str_contains($bootstrap, '(tenant_id, created_at DESC, id DESC)'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Admin catalog pagination contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Admin catalog pagination contract: OK\n";
