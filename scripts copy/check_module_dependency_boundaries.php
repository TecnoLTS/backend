<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$moduleRoot = $root . '/src/Modules';

/** @var array<string, list<string>> $ownedRepositories */
$ownedRepositories = [
    'CatalogInventory' => [
        'ProductReferenceCatalogRepository',
        'ProductRepository',
        'ProductReviewRepository',
        'PurchaseInvoiceRepository',
    ],
    'Commerce' => [
        'CustomerAuthSecurityRepository',
        'CustomerRepository',
        'DiscountRepository',
        'OrderRepository',
        'PosRepository',
        'QuotationRepository',
    ],
    'IdentityPlatform' => [
        'AuthSecurityRepository',
        'PasswordResetTokenRepository',
        'SettingsRepository',
        'UserRepository',
    ],
    'ReportingFinance' => [
        'BusinessExpenseRepository',
        'FinancialPeriodRepository',
    ],
];

$violations = [];
$files = [];
foreach (glob($moduleRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDirectory) {
    foreach (['Controllers', 'Application', 'Infrastructure'] as $layer) {
        $layerDirectory = $moduleDirectory . '/' . $layer;
        if (!is_dir($layerDirectory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($layerDirectory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }
}
$files = array_values(array_unique($files));
sort($files);

foreach ($files as $file) {
    $relative = substr($file, strlen($moduleRoot) + 1);
    $parts = explode('/', $relative);
    $owner = $parts[0] ?? '';
    $layer = $parts[1] ?? '';
    $source = file_get_contents($file);
    if (!is_string($source)) {
        $violations[] = $relative . ': no se pudo leer';
        continue;
    }

    preg_match_all(
        '/^\s*use\s+App\\\\Modules\\\\([^\\\\;]+)\\\\([^\\\\;]+)\\\\/m',
        $source,
        $moduleUses,
        PREG_SET_ORDER
    );
    foreach ($moduleUses as $moduleUse) {
        $dependency = $moduleUse[1] ?? '';
        $dependencyLayer = $moduleUse[2] ?? '';
        if ($dependency === $owner) {
            continue;
        }
        if ($layer === 'Infrastructure') {
            if ($dependencyLayer === 'Infrastructure') {
                $violations[] = sprintf(
                    '%s: Infrastructure importa la infraestructura privada de %s; consuma su capacidad Application/Domain',
                    $relative,
                    $dependency
                );
            }
        } else {
            $violations[] = sprintf(
                '%s: %s depende directamente del modulo %s; consuma un puerto propio y deje el adaptador en Infrastructure',
                $relative,
                $layer,
                $dependency
            );
        }
    }

    if ($layer !== 'Controllers') {
        continue;
    }

    preg_match_all('/^\s*use\s+App\\\\Repositories\\\\([A-Za-z0-9_]+)\s*;/m', $source, $repositoryUses);
    foreach (array_unique($repositoryUses[1] ?? []) as $repository) {
        if (!in_array($repository, $ownedRepositories[$owner] ?? [], true)) {
            $violations[] = sprintf(
                '%s: controlador consume %s fuera de su ownership; use un puerto de aplicacion',
                $relative,
                $repository
            );
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Module dependency boundary failed:\n- " . implode("\n- ", $violations) . "\n");
    exit(1);
}

printf("Module dependency boundaries: OK files=%d\n", count($files));
