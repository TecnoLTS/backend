<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Application\LoyaltyReportService;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

TenantContext::set([
    'id' => 'fidepuntos',
    'slug' => 'fidepuntos',
    'name' => 'Fidepuntos',
]);

$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$service = new LoyaltyReportService($pdo);
$today = new DateTimeImmutable('today', new DateTimeZone('America/Guayaquil'));
$report = [
    'tenant_id' => 'fidepuntos',
    'started_at' => date(DATE_ATOM),
    'checks' => [],
];

$recordException = static function (callable $callback): array {
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return ['passed' => true, 'message' => $exception->getMessage()];
    }

    return ['passed' => false, 'message' => 'La entrada invalida fue aceptada.'];
};

$reportKeys = [
    'executive-summary',
    'point-activity',
    'members-tiers',
    'card-adoption',
    'redemptions-rewards',
    'risk-events',
    'audit-events',
    'api-usage',
];
$contractTotals = [];

foreach ($reportKeys as $reportKey) {
    $payload = $service->report($reportKey, [
        'contract' => 'v2',
        'mode' => 'range',
        'from' => $today->sub(new DateInterval('P6D'))->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
        'limit' => 5,
        'offset' => 0,
    ]);
    $report['checks']['contract_' . $reportKey] = [
        'passed' => ($payload['schemaVersion'] ?? null) === 2
            && is_array($payload['metrics'] ?? null)
            && is_array($payload['charts'] ?? null)
            && is_array($payload['table']['columns'] ?? null)
            && is_array($payload['table']['rows'] ?? null)
            && isset($payload['table']['page']['total']),
        'rows' => $payload['table']['page']['total'] ?? null,
    ];
    $contractTotals[$reportKey] = (int)($payload['table']['page']['total'] ?? -1);
}

// Todas las variantes deben poder construir su primera pagina de exportacion
// con el orden canonico del keyset, incluso cuando actualmente tengan cero filas.
foreach ($reportKeys as $reportKey) {
    $smokeFilters = [
        'contract' => 'v2',
        'mode' => 'range',
        'from' => $today->sub(new DateInterval('P6D'))->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
    ];
    $smokeCsv = $service->reportCsvFile($reportKey, $smokeFilters);
    $report['checks']['export_cursor_' . $reportKey] = [
        'passed' => ($smokeCsv['rowCount'] ?? -1) === ($contractTotals[$reportKey] ?? -2),
        'rows' => $smokeCsv['rowCount'] ?? null,
    ];
    @unlink($smokeCsv['path']);
}

$day = $service->report('executive-summary', [
    'contract' => 'v2',
    'mode' => 'day',
    'date' => $today->format('Y-m-d'),
]);
$report['checks']['day_has_hourly_buckets'] = [
    'passed' => count($day['charts'][0]['categories'] ?? []) === 24
        && ($day['charts'][0]['granularity'] ?? null) === 'hour',
    'buckets' => count($day['charts'][0]['categories'] ?? []),
];

$leap = $service->report('executive-summary', [
    'contract' => 'v2',
    'mode' => 'day',
    'date' => '2024-02-29',
]);
$report['checks']['leap_day'] = [
    'passed' => ($leap['period']['from'] ?? null) === '2024-02-29'
        && ($leap['period']['to'] ?? null) === '2024-02-29',
];

$allowedFrom = $today->sub(new DateInterval('P365D'))->format('Y-m-d');
$allowed = $service->report('executive-summary', [
    'contract' => 'v2',
    'mode' => 'range',
    'from' => $allowedFrom,
    'to' => $today->format('Y-m-d'),
    'limit' => 1,
]);
$report['checks']['range_366_days'] = [
    'passed' => ($allowed['period']['from'] ?? null) === $allowedFrom,
];
$report['checks']['range_367_days_blocked'] = $recordException(
    fn() => $service->report('executive-summary', [
        'contract' => 'v2',
        'mode' => 'range',
        'from' => $today->sub(new DateInterval('P366D'))->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
    ])
);
$report['checks']['invalid_date_blocked'] = $recordException(
    fn() => $service->report('executive-summary', [
        'contract' => 'v2',
        'mode' => 'day',
        'date' => '2026-02-30',
    ])
);
$report['checks']['future_date_blocked'] = $recordException(
    fn() => $service->report('executive-summary', [
        'contract' => 'v2',
        'mode' => 'day',
        'date' => $today->add(new DateInterval('P1D'))->format('Y-m-d'),
    ])
);
$report['checks']['inverted_range_blocked'] = $recordException(
    fn() => $service->report('executive-summary', [
        'contract' => 'v2',
        'mode' => 'range',
        'from' => $today->format('Y-m-d'),
        'to' => $today->sub(new DateInterval('P1D'))->format('Y-m-d'),
    ])
);

$reconciliation = $service->report('ledger-reconciliation', [
    'contract' => 'v2',
    'mode' => 'as_of',
    'date' => $today->format('Y-m-d'),
    'discrepancies' => 'all',
]);
$report['checks']['reconciliation_as_of'] = [
    'passed' => ($reconciliation['scope'] ?? null) === 'as_of'
        && ($reconciliation['appliedFilters']['mode'] ?? null) === 'as_of',
    'accounts' => $reconciliation['table']['page']['total'] ?? null,
];
$reconciliationCsv = $service->reportCsvFile('ledger-reconciliation', [
    'contract' => 'v2',
    'mode' => 'as_of',
    'date' => $today->format('Y-m-d'),
    'discrepancies' => 'all',
]);
$report['checks']['export_cursor_ledger-reconciliation'] = [
    'passed' => ($reconciliationCsv['rowCount'] ?? -1) === ($reconciliation['table']['page']['total'] ?? -2),
    'rows' => $reconciliationCsv['rowCount'] ?? null,
];
@unlink($reconciliationCsv['path']);

$auditFilters = [
    'contract' => 'v2',
    'mode' => 'range',
    'from' => '2026-07-01',
    'to' => min($today->format('Y-m-d'), '2026-07-13'),
];
$audit = $service->report('audit-events', $auditFilters + ['limit' => 50]);
$csv = $service->reportCsvFile('audit-events', $auditFilters);
$xlsx = $service->reportExcelFile('audit-events', $auditFilters);
$expectedAuditRows = (int)($audit['table']['page']['total'] ?? -1);
$csvPhysicalRows = 0;
$csvHandle = fopen($csv['path'], 'rb');
if ($csvHandle !== false) {
    $expectedHeaders = array_column($audit['table']['columns'] ?? [], 'label');
    $insideData = false;
    while (($fields = fgetcsv($csvHandle, null, ',', '"', '')) !== false) {
        if (!$insideData && $fields === $expectedHeaders) {
            $insideData = true;
            continue;
        }
        if ($insideData && $fields !== [null] && $fields !== []) {
            $csvPhysicalRows++;
        }
    }
    fclose($csvHandle);
}

$sheetNames = [];
$xlsxPhysicalRows = -1;
$xlsxChartParts = 0;
$xlsxChartDrawingLinked = false;
$zip = new ZipArchive();
if ($zip->open($xlsx['path']) === true) {
        $workbookXml = (string)$zip->getFromName('xl/workbook.xml');
        preg_match_all('/<sheet name="([^"]+)"/', $workbookXml, $matches);
        $sheetNames = $matches[1] ?? [];
        $dataXml = (string)$zip->getFromName('xl/worksheets/sheet3.xml');
        $xlsxPhysicalRows = max(0, substr_count($dataXml, '<row ') - 1);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            if (preg_match('#^xl/charts/chart\d+\.xml$#', $name) === 1) {
                $xlsxChartParts++;
            }
        }
        $chartSheetXml = (string)$zip->getFromName('xl/worksheets/sheet2.xml');
        $chartSheetRels = (string)$zip->getFromName('xl/worksheets/_rels/sheet2.xml.rels');
        $drawingXml = (string)$zip->getFromName('xl/drawings/drawing2.xml');
        $xlsxChartDrawingLinked = str_contains($chartSheetXml, '<drawing r:id="rId1"')
            && str_contains($chartSheetRels, 'drawings/drawing2.xml')
            && str_contains($drawingXml, 'relationships" r:id="rId1"');
        $zip->close();
}
$report['checks']['audit_export_complete'] = [
    'passed' => ($csv['rowCount'] ?? -1) === $expectedAuditRows
        && ($xlsx['rowCount'] ?? -1) === $expectedAuditRows
        && $csvPhysicalRows === $expectedAuditRows
        && $xlsxPhysicalRows === $expectedAuditRows
        && $expectedAuditRows >= 229,
    'rows' => $expectedAuditRows,
    'csv_physical_rows' => $csvPhysicalRows,
    'xlsx_physical_rows' => $xlsxPhysicalRows,
    'csv_bytes' => $csv['size'] ?? null,
    'xlsx_bytes' => $xlsx['size'] ?? null,
];
$report['checks']['xlsx_four_sheets'] = [
    'passed' => $sheetNames === ['Resumen', 'Gráficos', 'Datos', 'Definiciones'],
    'sheets' => $sheetNames,
];
$report['checks']['xlsx_embeds_charts'] = [
    'passed' => $xlsxChartParts > 0 && $xlsxChartDrawingLinked,
    'chart_parts' => $xlsxChartParts,
    'drawing_linked' => $xlsxChartDrawingLinked,
];
@unlink($csv['path']);
@unlink($xlsx['path']);

// Fuerza mas de un bloque (EXPORT_BATCH_SIZE=1000) dentro de una transaccion
// que se revierte: valida que el keyset canonico no omita ni duplique filas y
// que el ejercicio no deje fixtures persistidos en QA.
$keysetActor = 'keyset-exercise-' . bin2hex(random_bytes(6));
$keysetCsv = null;
try {
    $pdo->beginTransaction();
    $insertKeyset = $pdo->prepare(
        "INSERT INTO loyalty_audit_events (
            id, tenant_id, actor_user_id, actor_type, action, subject_type,
            subject_id, reason, created_at
         )
         SELECT :prefix || LPAD(series::text, 4, '0'), 'fidepuntos', :actor,
                'exercise', 'report.keyset', 'report-row', series::text,
                'fixture transitorio',
                TIMESTAMP '2026-07-13 12:00:00' + series * INTERVAL '1 microsecond'
         FROM generate_series(1, 1005) AS series"
    );
    $insertKeyset->execute(['prefix' => $keysetActor . '-', 'actor' => $keysetActor]);
    $keysetCsv = $service->reportCsvFile('audit-events', [
        'contract' => 'v2',
        'mode' => 'day',
        'date' => '2026-07-13',
        'actor' => $keysetActor,
    ]);
    $pdo->rollBack();

    $keysetRows = 0;
    $keysetIds = [];
    $keysetHandle = fopen($keysetCsv['path'], 'rb');
    if ($keysetHandle !== false) {
        $insideKeysetData = false;
        while (($fields = fgetcsv($keysetHandle, null, ',', '"', '')) !== false) {
            if (!$insideKeysetData && ($fields[0] ?? null) === 'Evento') {
                $insideKeysetData = true;
                continue;
            }
            if ($insideKeysetData && isset($fields[0]) && $fields[0] !== '') {
                $keysetRows++;
                $keysetIds[(string)$fields[0]] = true;
            }
        }
        fclose($keysetHandle);
    }
    $report['checks']['export_keyset_multiple_batches'] = [
        'passed' => ($keysetCsv['rowCount'] ?? -1) === 1005
            && $keysetRows === 1005
            && count($keysetIds) === 1005,
        'declared_rows' => $keysetCsv['rowCount'] ?? null,
        'physical_rows' => $keysetRows,
        'unique_ids' => count($keysetIds),
    ];
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $report['checks']['export_keyset_multiple_batches'] = [
        'passed' => false,
        'message' => $exception->getMessage(),
    ];
} finally {
    if (is_array($keysetCsv) && isset($keysetCsv['path'])) {
        @unlink($keysetCsv['path']);
    }
}

// Una excepcion durante la hoja Datos debe cerrar el descriptor y borrar el
// temporal parcial; el workbook nunca debe recoger una hoja incompleta.
$temporarySheetsBefore = glob(sys_get_temp_dir() . '/loyalty-report-sheet-*') ?: [];
$writeDataWorksheet = new ReflectionMethod(LoyaltyReportService::class, 'writeDataWorksheet');
$cleanupExceptionObserved = false;
try {
    $writeDataWorksheet->invoke($service, 'reporte-inexistente', ['columns' => []], [], [], 0);
} catch (Throwable) {
    $cleanupExceptionObserved = true;
}
$temporarySheetsAfter = glob(sys_get_temp_dir() . '/loyalty-report-sheet-*') ?: [];
$report['checks']['xlsx_partial_sheet_cleanup'] = [
    'passed' => $cleanupExceptionObserved
        && array_values(array_diff($temporarySheetsAfter, $temporarySheetsBefore)) === [],
];

$reflection = new ReflectionMethod(LoyaltyReportService::class, 'spreadsheetText');
$payloads = ['=CMD()', '+SUM(A1:A2)', '-2+3', '@SUM(A1:A2)', "\tformula", "\rformula"];
$neutralized = array_map(fn(string $value): string => (string)$reflection->invoke($service, $value), $payloads);
$report['checks']['csv_formula_neutralization'] = [
    'passed' => count(array_filter($neutralized, static fn(string $value): bool => str_starts_with($value, "'"))) === count($payloads),
    'samples' => $neutralized,
];
$longCell = str_repeat('a', 40000);
$boundedCell = (string)$reflection->invoke($service, $longCell);
$report['checks']['spreadsheet_cell_bounded'] = [
    'passed' => mb_strlen($boundedCell, 'UTF-8') <= 32000 && str_ends_with($boundedCell, '[TRUNCATED]'),
    'length' => mb_strlen($boundedCell, 'UTF-8'),
];

$sizeGuard = new ReflectionMethod(LoyaltyReportService::class, 'assertExportSize');
$tooLargeBlocked = false;
try {
    $sizeGuard->invoke($service, 100001);
} catch (Throwable $exception) {
    $tooLargeBlocked = $exception instanceof App\Modules\LoyaltyRewards\Domain\LoyaltyReportExportTooLargeException;
}
$report['checks']['export_100001_blocked'] = ['passed' => $tooLargeBlocked];

$otherTenant = 'paramascotasec';
TenantContext::set(['id' => $otherTenant, 'slug' => $otherTenant, 'name' => $otherTenant]);
$otherService = new LoyaltyReportService($pdo);
$otherAudit = $otherService->report('audit-events', $auditFilters + ['limit' => 1]);
$statement = $pdo->prepare(
    'SELECT COUNT(*) FROM loyalty_audit_events
     WHERE tenant_id = :tenant_id AND created_at >= :from_start AND created_at < :to_exclusive'
);
$statement->execute([
    'tenant_id' => $otherTenant,
    'from_start' => $auditFilters['from'] . ' 00:00:00',
    'to_exclusive' => (new DateTimeImmutable($auditFilters['to']))->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00'),
]);
$otherExpected = (int)$statement->fetchColumn();
$report['checks']['tenant_isolation'] = [
    'passed' => (int)($otherAudit['table']['page']['total'] ?? -1) === $otherExpected,
    'tenant' => $otherTenant,
    'rows' => $otherExpected,
];
TenantContext::set(['id' => 'fidepuntos', 'slug' => 'fidepuntos', 'name' => 'Fidepuntos']);

$report['finished_at'] = date(DATE_ATOM);
$report['passed'] = !in_array(false, array_map(
    static fn(array $check): bool => (bool)($check['passed'] ?? false),
    $report['checks']
), true);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($report['passed'] ? 0 : 1);
