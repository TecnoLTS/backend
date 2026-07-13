<?php

namespace App\Modules\LoyaltyRewards\Application;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\LoyaltyReportExportTooLargeException;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Contrato de reportes Loyalty v2.
 *
 * El contrato v1 permanece en LoyaltyRepository. Esta clase concentra
 * validacion temporal, consultas tenant-aware, snapshots consistentes y
 * exportaciones completas para evitar que pantalla y archivos diverjan.
 */
final class LoyaltyReportService
{
    public const SCHEMA_VERSION = 2;
    public const MAX_RANGE_DAYS = 366;
    public const MAX_EXPORT_ROWS = 100000;
    private const DEFAULT_TIMEZONE = 'America/Guayaquil';
    private const EXPORT_BATCH_SIZE = 1000;
    private const MAX_SPREADSHEET_CELL_CHARS = 32000;

    private PDO $pdo;
    private ?int $knownExportRowCount = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
    }

    /** @return array<int, array<string, mixed>> */
    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->definitions() as $key => $definition) {
            $catalog[] = [
                'key' => $key,
                'title' => $definition['title'],
                'purpose' => $definition['purpose'],
                'scope' => $definition['scope'],
                'schemaVersion' => self::SCHEMA_VERSION,
                'modes' => $key === 'ledger-reconciliation'
                    ? ['as_of']
                    : ['day', 'range'],
                'columns' => $definition['columns'],
                'defaultSort' => $definition['defaultSort'],
                'defaultDirection' => $definition['defaultDirection'],
            ];
        }

        return $catalog;
    }

    /** @return array<string, mixed> */
    public function report(string $reportKey, array $filters = []): array
    {
        $definition = $this->definition($reportKey);
        $context = $this->reportContext($reportKey, $filters);

        return $this->withReadSnapshot(function () use ($reportKey, $definition, $context, $filters): array {
            $overview = $this->overview($reportKey, $context, $filters);
            $page = $this->tablePage($reportKey, $context, $filters);
            $generatedAt = $this->now($context['timezone'])->format(DATE_ATOM);

            return [
                'schemaVersion' => self::SCHEMA_VERSION,
                'key' => $reportKey,
                'title' => $definition['title'],
                'purpose' => $definition['purpose'],
                'scope' => $definition['scope'],
                'appliedFilters' => $context['appliedFilters'] + $page['appliedFilters'],
                'period' => [
                    'from' => $context['from'],
                    'to' => $context['to'],
                    'timezone' => $context['timezone'],
                    'inclusive' => true,
                    'generatedAt' => $generatedAt,
                    'snapshotAt' => in_array($definition['scope'], ['hybrid', 'as_of'], true)
                        ? $generatedAt
                        : null,
                ],
                'metrics' => $overview['metrics'],
                'charts' => $overview['charts'],
                'table' => [
                    'columns' => $definition['columns'],
                    'rows' => $page['rows'],
                    'page' => [
                        'total' => $page['total'],
                        'limit' => $page['limit'],
                        'offset' => $page['offset'],
                        'hasMore' => ($page['offset'] + count($page['rows'])) < $page['total'],
                    ],
                ],
                'export' => [
                    'rowCount' => $page['total'],
                    'maxRows' => self::MAX_EXPORT_ROWS,
                    'available' => $page['total'] <= self::MAX_EXPORT_ROWS,
                ],
            ];
        });
    }

    /** @return array{filename:string, content:string, rowCount:int, generatedAt:string} */
    public function reportCsv(string $reportKey, array $filters = []): array
    {
        $export = $this->reportCsvFile($reportKey, $filters);
        $path = $export['path'];
        try {
            $content = file_get_contents($path);
            if (!is_string($content)) {
                throw new \RuntimeException('No se pudo leer la exportacion CSV.');
            }

            unset($export['path'], $export['size']);
            $export['content'] = $content;

            return $export;
        } finally {
            @unlink($path);
        }
    }

    /** @return array{filename:string, path:string, size:int, rowCount:int, generatedAt:string} */
    public function reportCsvFile(string $reportKey, array $filters = []): array
    {
        $definition = $this->definition($reportKey);
        $context = $this->reportContext($reportKey, $filters);

        return $this->withReadSnapshot(function () use ($reportKey, $definition, $context, $filters): array {
            $overview = $this->overview($reportKey, $context, $filters);
            $count = $this->tableCount($reportKey, $context, $filters);
            $this->assertExportSize($count);
            $generatedAt = $this->now($context['timezone'])->format(DATE_ATOM);
            $path = tempnam(sys_get_temp_dir(), 'loyalty-report-csv-');
            if (!is_string($path)) {
                throw new \RuntimeException('No se pudo preparar la exportacion CSV.');
            }
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                @unlink($path);
                throw new \RuntimeException('No se pudo abrir la exportacion CSV.');
            }

            try {
                fwrite($handle, "\xEF\xBB\xBF");
                $this->csvLine($handle, ['Reporte', $definition['title']]);
                $this->csvLine($handle, ['Proposito', $definition['purpose']]);
                $this->csvLine($handle, ['Tenant', $context['tenantId']]);
                $this->csvLine($handle, ['Periodo', $context['from'] . ' a ' . $context['to']]);
                $this->csvLine($handle, ['Zona horaria', $context['timezone']]);
                $this->csvLine($handle, ['Generado', $generatedAt]);
                $this->csvLine($handle, []);
                $this->csvLine($handle, ['Indicador', 'Valor', 'Definicion']);
                foreach ($overview['metrics'] as $metric) {
                    $this->csvLine($handle, [$metric['label'], $metric['value'], $metric['definition']]);
                }
                $this->csvLine($handle, []);
                $this->csvLine($handle, array_column($definition['columns'], 'label'));

                $this->iterateAllRows(
                    $reportKey,
                    $context,
                    $filters,
                    function (array $rows) use ($handle, $definition): void {
                        foreach ($rows as $row) {
                            $fields = [];
                            foreach ($definition['columns'] as $column) {
                                $fields[] = $this->spreadsheetText($row[$column['key']] ?? null);
                            }
                            $this->csvLine($handle, $fields);
                        }
                    },
                    $count
                );
                fflush($handle);
                fclose($handle);
            } catch (\Throwable $exception) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($path);
                throw $exception;
            }

            return [
                'filename' => $this->filename($reportKey, 'csv'),
                'path' => $path,
                'size' => (int)(filesize($path) ?: 0),
                'rowCount' => $count,
                'generatedAt' => $generatedAt,
            ];
        });
    }

    /** @return array{filename:string, content:string, rowCount:int, generatedAt:string} */
    public function reportExcel(string $reportKey, array $filters = []): array
    {
        $export = $this->reportExcelFile($reportKey, $filters);
        $path = $export['path'];
        try {
            $content = file_get_contents($path);
            if (!is_string($content)) {
                throw new \RuntimeException('No se pudo leer la exportacion Excel.');
            }

            unset($export['path'], $export['size']);
            $export['content'] = $content;

            return $export;
        } finally {
            @unlink($path);
        }
    }

    /** @return array{filename:string, path:string, size:int, rowCount:int, generatedAt:string} */
    public function reportExcelFile(string $reportKey, array $filters = []): array
    {
        $definition = $this->definition($reportKey);
        $context = $this->reportContext($reportKey, $filters);

        return $this->withReadSnapshot(function () use ($reportKey, $definition, $context, $filters): array {
            $overview = $this->overview($reportKey, $context, $filters);
            $count = $this->tableCount($reportKey, $context, $filters);
            $this->assertExportSize($count);
            $generatedAt = $this->now($context['timezone'])->format(DATE_ATOM);

            $summaryRows = [
                [['value' => $definition['title'], 'style' => 1]],
                [['value' => 'Tenant', 'style' => 2], ['value' => $context['tenantId']]],
                [['value' => 'Periodo', 'style' => 2], ['value' => $context['from'] . ' a ' . $context['to']]],
                [['value' => 'Zona horaria', 'style' => 2], ['value' => $context['timezone']]],
                [['value' => 'Generado', 'style' => 2], ['value' => $generatedAt, 'type' => 'datetime']],
                [],
                [['value' => 'Indicador', 'style' => 2], ['value' => 'Valor', 'style' => 2], ['value' => 'Definicion', 'style' => 2]],
            ];
            foreach ($overview['metrics'] as $metric) {
                $summaryRows[] = [
                    ['value' => $metric['label']],
                    ['value' => $metric['value'], 'type' => $metric['format']],
                    ['value' => $metric['definition']],
                ];
            }

            $chartRows = [[
                ['value' => 'Grafico', 'style' => 2],
                ['value' => 'Categoria', 'style' => 2],
                ['value' => 'Serie', 'style' => 2],
                ['value' => 'Valor', 'style' => 2],
                ['value' => 'Unidad', 'style' => 2],
            ]];
            foreach ($overview['charts'] as $chart) {
                foreach ($chart['series'] as $series) {
                    foreach ($chart['categories'] as $index => $category) {
                        $chartRows[] = [
                            ['value' => $chart['title']],
                            ['value' => $category],
                            ['value' => $series['label']],
                            ['value' => (int)($series['data'][$index] ?? 0), 'type' => 'integer'],
                            ['value' => $chart['unit']],
                        ];
                    }
                }
            }

            $definitionRows = [[
                ['value' => 'Elemento', 'style' => 2],
                ['value' => 'Tipo', 'style' => 2],
                ['value' => 'Definicion', 'style' => 2],
            ]];
            $definitionRows[] = [
                ['value' => 'Reporte'],
                ['value' => $definition['scope']],
                ['value' => $definition['purpose']],
            ];
            foreach ($definition['columns'] as $column) {
                $definitionRows[] = [
                    ['value' => $column['label']],
                    ['value' => $column['type']],
                    ['value' => $column['definition']],
                ];
            }
            foreach ($overview['metrics'] as $metric) {
                $definitionRows[] = [
                    ['value' => $metric['label']],
                    ['value' => $metric['format']],
                    ['value' => $metric['definition']],
                ];
            }

            $sheets = [];
            try {
                $sheets[] = ['name' => 'Resumen', 'path' => $this->writeWorksheet($summaryRows, 7)];
                $sheets[] = ['name' => 'Gráficos', 'path' => $this->writeWorksheet($chartRows, 1)];
                $sheets[] = [
                    'name' => 'Datos',
                    'path' => $this->writeDataWorksheet($reportKey, $definition, $context, $filters, $count),
                ];
                $sheets[] = ['name' => 'Definiciones', 'path' => $this->writeWorksheet($definitionRows, 1)];
                $path = $this->buildWorkbookFile($definition['title'], $sheets);
            } finally {
                foreach ($sheets as $sheet) {
                    @unlink($sheet['path']);
                }
            }

            return [
                'filename' => $this->filename($reportKey, 'xlsx'),
                'path' => $path,
                'size' => (int)(filesize($path) ?: 0),
                'rowCount' => $count,
                'generatedAt' => $generatedAt,
            ];
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            'executive-summary' => [
                'title' => 'Resumen ejecutivo',
                'purpose' => 'Resume compras, canjes, reversas, ajustes y variacion neta del periodo.',
                'scope' => 'hybrid',
                'defaultSort' => 'bucket',
                'defaultDirection' => 'asc',
                'columns' => [
                    $this->column('bucket', 'Periodo', 'datetime', true, 'Hora o dia del intervalo segun el modo.'),
                    $this->column('purchases', 'Compras', 'integer', true, 'Compras acreditadas en el intervalo.'),
                    $this->column('purchasePoints', 'Puntos por compras', 'points', true, 'Puntos emitidos por compras.'),
                    $this->column('redemptionPoints', 'Puntos canjeados', 'points', true, 'Puntos debitados por canjes.'),
                    $this->column('reversalPoints', 'Reversas', 'points', true, 'Total original recuperado: saldo retirado mas deuda creada.'),
                    $this->column('reversalDebtCreated', 'Deuda por reversas', 'points', true, 'Porcion de las reversas que no pudo retirarse del saldo disponible.'),
                    $this->column('adjustmentPoints', 'Ajustes netos', 'points', true, 'Resultado economico del ajuste, incluida la deuda amortizada.'),
                    $this->column('netPoints', 'Variacion neta', 'points', true, 'Cambio del saldo disponible menos el cambio de deuda.'),
                ],
            ],
            'point-activity' => [
                'title' => 'Actividad de puntos',
                'purpose' => 'Detalla cada movimiento del libro mayor y su saldo posterior.',
                'scope' => 'period',
                'defaultSort' => 'createdAt',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('id', 'Movimiento', 'text', false, 'Identificador estable del movimiento.'),
                    $this->column('createdAt', 'Fecha y hora', 'datetime', true, 'Momento local del movimiento.'),
                    $this->column('accountId', 'Cuenta', 'text', true, 'Cuenta Loyalty afectada.'),
                    $this->column('accountName', 'Socio', 'text', true, 'Nombre del socio.'),
                    $this->column('ledgerKind', 'Libro', 'text', true, 'Libro de saldo disponible o libro de deuda.'),
                    $this->column('entryType', 'Tipo', 'text', true, 'Tipo canonico del movimiento.'),
                    $this->column('points', 'Puntos', 'points', true, 'Delta con signo; en deuda, positivo crea deuda y negativo la amortiza.'),
                    $this->column('balanceAfter', 'Saldo/deuda posterior', 'points', true, 'Saldo disponible o deuda despues del movimiento.'),
                    $this->column('reference', 'Referencia', 'text', false, 'Referencia operativa normalizada.'),
                    $this->column('source', 'Canal', 'text', true, 'Canal que origino el movimiento.'),
                    $this->column('actor', 'Actor', 'text', true, 'Usuario o integracion que ejecuto el movimiento.'),
                    $this->column('reason', 'Motivo', 'text', false, 'Motivo declarado cuando aplica.'),
                    $this->column('evidence', 'Evidencia', 'text', false, 'Referencia a evidencia cuando aplica.'),
                ],
            ],
            'members-tiers' => [
                'title' => 'Socios y niveles',
                'purpose' => 'Combina altas y actividad del periodo con una foto actual de niveles y saldos.',
                'scope' => 'hybrid',
                'defaultSort' => 'currentMembers',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('tier', 'Nivel', 'text', true, 'Nivel actual del socio.'),
                    $this->column('status', 'Estado', 'text', true, 'Estado actual del socio.'),
                    $this->column('walletPlatform', 'Tarjeta', 'text', true, 'Plataforma Wallet actual.'),
                    $this->column('currentMembers', 'Socios actuales', 'integer', true, 'Socios en la foto actual.'),
                    $this->column('newMembers', 'Altas del periodo', 'integer', true, 'Socios creados en el periodo.'),
                    $this->column('activeMembers', 'Con actividad', 'integer', true, 'Socios con movimientos en el periodo.'),
                    $this->column('currentBalance', 'Saldo actual', 'points', true, 'Saldo actual agregado.'),
                ],
            ],
            'card-adoption' => [
                'title' => 'Adopcion de tarjetas digitales',
                'purpose' => 'Muestra nuevas tarjetas del periodo y su distribucion actual por plataforma y estado.',
                'scope' => 'hybrid',
                'defaultSort' => 'currentPasses',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('platform', 'Plataforma', 'text', true, 'Plataforma actual del pase.'),
                    $this->column('status', 'Estado', 'text', true, 'Estado actual del pase.'),
                    $this->column('currentPasses', 'Tarjetas actuales', 'integer', true, 'Tarjetas en la foto actual.'),
                    $this->column('createdInPeriod', 'Creadas en el periodo', 'integer', true, 'Tarjetas creadas durante el periodo.'),
                ],
            ],
            'redemptions-rewards' => [
                'title' => 'Canjes y premios',
                'purpose' => 'Detalla solicitudes y canjes, su costo, estado y forma de entrega.',
                'scope' => 'hybrid',
                'defaultSort' => 'createdAt',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('id', 'Canje', 'text', false, 'Identificador estable del canje.'),
                    $this->column('createdAt', 'Fecha y hora', 'datetime', true, 'Momento de creacion.'),
                    $this->column('accountId', 'Cuenta', 'text', true, 'Cuenta Loyalty.'),
                    $this->column('accountName', 'Socio', 'text', true, 'Socio que solicito el premio.'),
                    $this->column('reward', 'Premio', 'text', true, 'Nombre actual del premio.'),
                    $this->column('points', 'Puntos', 'points', true, 'Costo reservado o debitado.'),
                    $this->column('source', 'Canal', 'text', true, 'Origen del canje.'),
                    $this->column('fulfillment', 'Entrega', 'text', true, 'Modalidad de entrega.'),
                    $this->column('status', 'Estado', 'text', true, 'Estado operativo del canje.'),
                    $this->column('resolvedAt', 'Resuelto', 'datetime', true, 'Momento de resolucion.'),
                    $this->column('actor', 'Actor', 'text', true, 'Operador que resolvio.'),
                    $this->column('resolution', 'Resolucion', 'text', false, 'Nota de resolucion del canje.'),
                    $this->column('restoredPoints', 'Puntos devueltos', 'points', true, 'Puntos restaurados por cancelacion o expiracion.'),
                    $this->column('currentStock', 'Stock actual', 'integer', true, 'Foto actual del stock del premio.'),
                ],
            ],
            'risk-events' => [
                'title' => 'Riesgo y antifraude',
                'purpose' => 'Presenta eventos de riesgo por severidad, tipo, estado y resolucion.',
                'scope' => 'period',
                'defaultSort' => 'createdAt',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('id', 'Evento', 'text', false, 'Identificador estable del evento.'),
                    $this->column('createdAt', 'Fecha y hora', 'datetime', true, 'Momento de deteccion.'),
                    $this->column('severity', 'Severidad', 'text', true, 'Severidad asignada.'),
                    $this->column('eventType', 'Tipo', 'text', true, 'Tipo canonico de riesgo.'),
                    $this->column('status', 'Estado', 'text', true, 'Estado de investigacion.'),
                    $this->column('accountId', 'Cuenta', 'text', true, 'Cuenta asociada cuando existe.'),
                    $this->column('reference', 'Referencia', 'text', false, 'Referencia operativa.'),
                    $this->column('message', 'Mensaje', 'text', false, 'Descripcion segura del hallazgo.'),
                    $this->column('metadata', 'Detalle', 'text', false, 'Metadatos sanitizados del evento.'),
                    $this->column('resolvedAt', 'Resuelto', 'datetime', true, 'Momento de resolucion.'),
                    $this->column('resolvedBy', 'Responsable', 'text', true, 'Operador que resolvio.'),
                ],
            ],
            'audit-events' => [
                'title' => 'Auditoria',
                'purpose' => 'Expone acciones administrativas con actor, sujeto, motivo y cambios.',
                'scope' => 'period',
                'defaultSort' => 'createdAt',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('id', 'Evento', 'text', false, 'Identificador estable del evento.'),
                    $this->column('createdAt', 'Fecha y hora', 'datetime', true, 'Momento de la accion.'),
                    $this->column('actor', 'Actor', 'text', true, 'Usuario o servicio responsable.'),
                    $this->column('actorType', 'Tipo de actor', 'text', true, 'Superficie o clase del actor.'),
                    $this->column('action', 'Accion', 'text', true, 'Accion canonica.'),
                    $this->column('subjectType', 'Tipo de sujeto', 'text', true, 'Clase del recurso afectado.'),
                    $this->column('subjectId', 'Sujeto', 'text', false, 'Identificador del recurso.'),
                    $this->column('reason', 'Motivo', 'text', false, 'Motivo declarado.'),
                    $this->column('before', 'Antes', 'text', false, 'Snapshot previo sanitizado.'),
                    $this->column('after', 'Despues', 'text', false, 'Snapshot posterior sanitizado.'),
                    $this->column('metadata', 'Detalle', 'text', false, 'Metadatos de auditoria sanitizados.'),
                ],
            ],
            'api-usage' => [
                'title' => 'Uso de API externa',
                'purpose' => 'Mide solicitudes diarias y consumo por cliente API.',
                'scope' => 'hybrid',
                'defaultSort' => 'date',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('date', 'Fecha', 'date', true, 'Dia local de consumo.'),
                    $this->column('clientId', 'Cliente', 'text', false, 'Identificador estable del cliente.'),
                    $this->column('client', 'Nombre', 'text', true, 'Nombre del cliente API.'),
                    $this->column('source', 'Fuente', 'text', true, 'Fuente inmutable de la credencial.'),
                    $this->column('status', 'Estado', 'text', true, 'Estado actual de la credencial.'),
                    $this->column('requests', 'Solicitudes', 'integer', true, 'Solicitudes registradas ese dia.'),
                    $this->column('rateLimit', 'Limite por minuto', 'integer', true, 'Cuota configurada actual.'),
                    $this->column('lastUsedAt', 'Ultimo uso', 'datetime', true, 'Ultimo uso observado.'),
                ],
            ],
            'ledger-reconciliation' => [
                'title' => 'Conciliacion de saldos',
                'purpose' => 'Reconstruye el ledger al corte y compara saldo posterior y estado actual.',
                'scope' => 'as_of',
                'defaultSort' => 'cutoffDifference',
                'defaultDirection' => 'desc',
                'columns' => [
                    $this->column('accountId', 'Cuenta', 'text', true, 'Cuenta Loyalty.'),
                    $this->column('accountName', 'Socio', 'text', true, 'Nombre del socio.'),
                    $this->column('integrityStatus', 'Integridad de cuenta', 'text', true, 'Indica si faltan el socio o la cuenta materializada.'),
                    $this->column('movementsAtCutoff', 'Movimientos al corte', 'integer', true, 'Cantidad de movimientos hasta el corte.'),
                    $this->column('lastMovementAt', 'Ultimo movimiento', 'datetime', true, 'Ultimo movimiento hasta el corte.'),
                    $this->column('ledgerAtCutoff', 'Ledger al corte', 'points', true, 'Suma del ledger hasta el corte.'),
                    $this->column('balanceAfterAtCutoff', 'Saldo posterior al corte', 'points', true, 'Ultimo balance_after registrado al corte.'),
                    $this->column('cutoffDifference', 'Diferencia al corte', 'points', true, 'Ledger al corte menos balance_after al corte.'),
                    $this->column('debtAtCutoff', 'Deuda al corte', 'points', true, 'Suma del ledger de deuda hasta el corte.'),
                    $this->column('debtAfterAtCutoff', 'Deuda posterior al corte', 'points', true, 'Ultimo debt_after registrado al corte.'),
                    $this->column('debtCutoffDifference', 'Diferencia deuda al corte', 'points', true, 'Ledger de deuda menos debt_after al corte.'),
                    $this->column('currentBalance', 'Saldo actual', 'points', true, 'Saldo actual de la cuenta.'),
                    $this->column('currentLedger', 'Ledger actual', 'points', true, 'Suma actual del ledger.'),
                    $this->column('currentDifference', 'Diferencia actual', 'points', true, 'Saldo actual menos ledger actual.'),
                    $this->column('currentDebt', 'Deuda actual', 'points', true, 'Deuda actual de la cuenta.'),
                    $this->column('currentDebtLedger', 'Ledger de deuda actual', 'points', true, 'Suma actual del ledger de deuda.'),
                    $this->column('currentDebtDifference', 'Diferencia deuda actual', 'points', true, 'Deuda actual menos ledger de deuda.'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function definition(string $reportKey): array
    {
        $definitions = $this->definitions();
        if (!isset($definitions[$reportKey])) {
            throw new \InvalidArgumentException('Reporte no disponible.');
        }

        return $definitions[$reportKey];
    }

    /** @return array<string, mixed> */
    private function column(string $key, string $label, string $type, bool $sortable, string $definition): array
    {
        return compact('key', 'label', 'type', 'sortable', 'definition');
    }

    /** @return array<string, mixed> */
    private function reportContext(string $reportKey, array $filters): array
    {
        $tenantId = $this->tenantId();
        $timezone = $this->tenantTimezone($tenantId);
        $zone = new DateTimeZone($timezone);
        $today = $this->now($timezone)->setTime(0, 0);
        $defaultMode = $reportKey === 'ledger-reconciliation' ? 'as_of' : 'range';
        $mode = strtolower(trim((string)($filters['mode'] ?? $defaultMode)));
        if (!in_array($mode, ['day', 'range', 'as_of'], true)) {
            throw new \InvalidArgumentException('El modo debe ser day, range o as_of.');
        }
        if ($reportKey === 'ledger-reconciliation' && $mode !== 'as_of') {
            throw new \InvalidArgumentException('La conciliacion requiere mode=as_of.');
        }
        if ($reportKey !== 'ledger-reconciliation' && $mode === 'as_of') {
            throw new \InvalidArgumentException('El modo as_of solo esta disponible para conciliacion.');
        }

        if ($mode === 'day') {
            $dateValue = trim((string)($filters['date'] ?? $filters['from'] ?? $today->format('Y-m-d')));
            $fromDate = $this->strictDate($dateValue, $zone);
            $toDate = $fromDate;
        } elseif ($mode === 'as_of') {
            $dateValue = trim((string)($filters['date'] ?? $filters['to'] ?? $today->format('Y-m-d')));
            $fromDate = $this->strictDate($dateValue, $zone);
            $toDate = $fromDate;
        } else {
            $fromValue = trim((string)($filters['from'] ?? $today->sub(new DateInterval('P29D'))->format('Y-m-d')));
            $toValue = trim((string)($filters['to'] ?? $today->format('Y-m-d')));
            $fromDate = $this->strictDate($fromValue, $zone);
            $toDate = $this->strictDate($toValue, $zone);
        }

        if ($fromDate > $today || $toDate > $today) {
            throw new \InvalidArgumentException('No se permiten fechas futuras.');
        }
        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
        }
        $inclusiveDays = (int)$fromDate->diff($toDate)->days + 1;
        if ($inclusiveDays > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException(sprintf(
                'El rango no puede superar %d dias inclusivos.',
                self::MAX_RANGE_DAYS
            ));
        }
        $this->assertCompatibleStorageTimezone($zone, $fromDate, $toDate);

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $toExclusive = $toDate->add(new DateInterval('P1D'));
        $applied = [
            'contract' => 'v2',
            'mode' => $mode,
            'from' => $from,
            'to' => $to,
        ];
        if ($mode === 'day' || $mode === 'as_of') {
            $applied['date'] = $to;
        }

        return [
            'tenantId' => $tenantId,
            'timezone' => $timezone,
            'mode' => $mode,
            'from' => $from,
            'to' => $to,
            'fromStart' => $fromDate->format('Y-m-d H:i:s'),
            'toExclusive' => $toExclusive->format('Y-m-d H:i:s'),
            'grain' => $mode === 'day' ? 'hour' : 'day',
            'appliedFilters' => $applied,
        ];
    }

    /**
     * Las tablas Loyalty actuales conservan timestamps sin zona. Mientras se
     * migra el almacenamiento a UTC/timestamptz, solo es seguro consultar un
     * tenant cuyo offset coincida durante todo el periodo con el de PostgreSQL.
     */
    private function assertCompatibleStorageTimezone(
        DateTimeZone $tenantZone,
        DateTimeImmutable $fromDate,
        DateTimeImmutable $toDate
    ): void {
        $storageName = (string)$this->scalar("SELECT current_setting('TimeZone')");
        try {
            $storageZone = new DateTimeZone($storageName);
        } catch (\Throwable) {
            throw new \RuntimeException('La zona horaria de almacenamiento no es valida.');
        }

        $cursor = $fromDate->sub(new DateInterval('P1D'));
        $end = $toDate->add(new DateInterval('P2D'));
        $utc = new DateTimeZone('UTC');
        while ($cursor < $end) {
            $instant = new DateTimeImmutable($cursor->format('Y-m-d') . ' 12:00:00', $utc);
            if ($tenantZone->getOffset($instant) !== $storageZone->getOffset($instant)) {
                throw new \InvalidArgumentException(
                    'La zona horaria del tenant aun no es compatible con el almacenamiento historico de Loyalty.'
                );
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
        }
    }

    private function strictDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Las fechas deben usar el formato YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException('La fecha indicada no existe.');
        }

        return $date;
    }

    private function tenantTimezone(string $tenantId): string
    {
        $value = $this->scalar(
            "SELECT settings #>> '{program,timezone}'
             FROM loyalty_program_settings
             WHERE tenant_id = :tenant_id
             LIMIT 1",
            ['tenant_id' => $tenantId]
        );
        $timezone = is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_TIMEZONE;
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        return $timezone;
    }

    private function now(string $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($timezone));
    }

    /** @template T @param callable():T $callback @return T */
    private function withReadSnapshot(callable $callback)
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function overview(string $reportKey, array $context, array $filters): array
    {
        return match ($reportKey) {
            'executive-summary' => $this->executiveOverview($context),
            'point-activity' => $this->activityOverview($context),
            'members-tiers' => $this->membersOverview($context),
            'card-adoption' => $this->cardsOverview($context),
            'redemptions-rewards' => $this->redemptionsOverview($context),
            'risk-events' => $this->riskOverview($context, $filters),
            'audit-events' => $this->auditOverview($context, $filters),
            'api-usage' => $this->apiOverview($context, $filters),
            'ledger-reconciliation' => $this->reconciliationOverview($context, $filters),
            default => throw new \InvalidArgumentException('Reporte no disponible.'),
        };
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,limit:int,offset:int,appliedFilters:array<string,mixed>} */
    private function tablePage(string $reportKey, array $context, array $filters): array
    {
        $limit = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $definition = $this->definition($reportKey);
        $sortable = array_column(
            array_values(array_filter($definition['columns'], static fn(array $column): bool => $column['sortable'])),
            'key'
        );
        $sort = trim((string)($filters['sort'] ?? $definition['defaultSort']));
        if (!in_array($sort, $sortable, true)) {
            $sort = $definition['defaultSort'];
        }
        $direction = strtolower(trim((string)($filters['direction'] ?? $definition['defaultDirection'])));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $definition['defaultDirection'];
        }

        $result = $this->queryTable($reportKey, $context, $filters, $limit, $offset, $sort, $direction);

        return $result + [
            'limit' => $limit,
            'offset' => $offset,
            'appliedFilters' => $this->specificAppliedFilters($reportKey, $filters) + [
                'sort' => $sort,
                'direction' => $direction,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    private function tableCount(string $reportKey, array $context, array $filters): int
    {
        $definition = $this->definition($reportKey);
        $result = $this->queryTable(
            $reportKey,
            $context,
            $filters,
            1,
            0,
            $definition['defaultSort'],
            $definition['defaultDirection'],
            true
        );

        return $result['total'];
    }

    /** @return array<string,mixed> */
    private function specificAppliedFilters(string $reportKey, array $filters): array
    {
        $allowed = match ($reportKey) {
            'point-activity' => ['entryType', 'source', 'accountId'],
            'members-tiers' => ['tier', 'status'],
            'card-adoption' => ['platform', 'status'],
            'redemptions-rewards' => ['status', 'rewardId', 'source'],
            'risk-events' => ['severity', 'status'],
            'audit-events' => ['action', 'actor'],
            'api-usage' => ['clientId', 'status'],
            'ledger-reconciliation' => ['discrepancies'],
            default => [],
        };
        $result = [];
        foreach ($allowed as $key) {
            if (isset($filters[$key]) && trim((string)$filters[$key]) !== '') {
                $result[$key] = trim((string)$filters[$key]);
            }
        }

        return $result;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function queryTable(
        string $reportKey,
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly = false
    ): array {
        return match ($reportKey) {
            'executive-summary' => $this->executiveTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'point-activity' => $this->activityTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'members-tiers' => $this->membersTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'card-adoption' => $this->cardsTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'redemptions-rewards' => $this->redemptionsTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'risk-events' => $this->riskTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'audit-events' => $this->auditTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'api-usage' => $this->apiTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            'ledger-reconciliation' => $this->reconciliationTable($context, $filters, $limit, $offset, $sort, $direction, $countOnly),
            default => throw new \InvalidArgumentException('Reporte no disponible.'),
        };
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function executiveOverview(array $context): array
    {
        $rows = $this->executiveTrend($context);
        $totals = [
            'purchases' => 0,
            'purchasePoints' => 0,
            'redemptionPoints' => 0,
            'reversalPoints' => 0,
            'reversalDebtCreated' => 0,
            'adjustmentPoints' => 0,
            'netPoints' => 0,
        ];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (int)$row[$key];
            }
        }
        $params = $this->rangeParams($context);
        $activeMembers = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND status = 'active'",
            ['tenant_id' => $context['tenantId']]
        );
        $newMembers = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_members
             WHERE tenant_id = :tenant_id AND created_at >= :from_start AND created_at < :to_exclusive',
            $params
        );
        $liability = (int)$this->scalar(
            'SELECT COALESCE(SUM(points), 0) FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id AND created_at < :to_exclusive',
            ['tenant_id' => $context['tenantId'], 'to_exclusive' => $context['toExclusive']]
        );
        $debtAtClose = (int)$this->scalar(
            'SELECT COALESCE(SUM(points), 0) FROM loyalty_debt_ledger
             WHERE tenant_id = :tenant_id AND created_at < :to_exclusive',
            ['tenant_id' => $context['tenantId'], 'to_exclusive' => $context['toExclusive']]
        );

        return [
            'metrics' => [
                $this->metric('activeMembers', 'Socios activos', $activeMembers, 'integer', 'Socios cuyo estado actual es activo.'),
                $this->metric('newMembers', 'Socios nuevos', $newMembers, 'integer', 'Socios creados dentro del periodo.'),
                $this->metric('purchasePoints', 'Puntos por compras', $totals['purchasePoints'], 'points', 'Creditos emitidos exclusivamente por compras.'),
                $this->metric('redemptionPoints', 'Puntos canjeados', $totals['redemptionPoints'], 'points', 'Debitos brutos por canjes.'),
                $this->metric('reversalPoints', 'Puntos reversados', $totals['reversalPoints'], 'points', 'Total original recuperado: saldo retirado mas deuda creada.'),
                $this->metric('reversalDebtCreated', 'Deuda creada por reversas', $totals['reversalDebtCreated'], 'points', 'Parte de las reversas registrada como deuda por saldo insuficiente.'),
                $this->metric('adjustmentPoints', 'Ajustes netos', $totals['adjustmentPoints'], 'points', 'Suma con signo de los ajustes, incluida la deuda amortizada.'),
                $this->metric('netPoints', 'Variacion neta', $totals['netPoints'], 'points', 'Cambio del saldo disponible menos el cambio de deuda en el periodo.'),
                $this->metric('liabilityAtClose', 'Saldo al cierre', $liability, 'points', 'Suma del ledger hasta el final inclusivo del periodo; no representa valor monetario.'),
                $this->metric('debtAtClose', 'Deuda al cierre', $debtAtClose, 'points', 'Suma del ledger de deuda hasta el final inclusivo del periodo.'),
            ],
            'charts' => [[
                'key' => 'executive-flow',
                'title' => 'Flujo de puntos',
                'type' => 'line',
                'granularity' => $context['grain'],
                'categories' => array_column($rows, 'bucket'),
                'series' => [
                    $this->series('purchases', 'Compras', array_column($rows, 'purchasePoints')),
                    $this->series('redemptions', 'Canjes', array_column($rows, 'redemptionPoints')),
                    $this->series('reversals', 'Reversas', array_column($rows, 'reversalPoints')),
                    $this->series('adjustments', 'Ajustes netos', array_column($rows, 'adjustmentPoints')),
                    $this->series('net', 'Variacion neta', array_column($rows, 'netPoints')),
                ],
                'unit' => 'points',
                'summary' => sprintf(
                    'El periodo registra %d puntos por compras, %d canjeados y una variacion neta de %d puntos.',
                    $totals['purchasePoints'],
                    $totals['redemptionPoints'],
                    $totals['netPoints']
                ),
            ]],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function executiveTrend(array $context): array
    {
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $raw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', created_at), '{$format}') AS bucket,
                    COUNT(*) FILTER (WHERE entry_type = 'purchase') AS purchases,
                    COALESCE(SUM(points) FILTER (WHERE entry_type = 'purchase' AND points > 0), 0) AS purchase_points,
                    ABS(COALESCE(SUM(points) FILTER (WHERE entry_type = 'redemption' AND points < 0), 0)) AS redemption_points,
                    ABS(COALESCE(SUM(points) FILTER (WHERE entry_type = 'reversal' AND points < 0), 0)) AS reversal_points,
                    COALESCE(SUM(points) FILTER (WHERE entry_type = 'adjustment'), 0) AS adjustment_points,
                    COALESCE(SUM(points), 0) AS net_points
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY 1
             ORDER BY 1",
            $this->rangeParams($context)
        );
        $debtRaw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', created_at), '{$format}') AS bucket,
                    COALESCE(SUM(points) FILTER (WHERE entry_type = 'purchase_reversal_debt' AND points > 0), 0) AS reversal_debt_created,
                    COALESCE(SUM(points) FILTER (
                        WHERE entry_type = 'debt_payment'
                          AND COALESCE(metadata->>'operation', '') = 'adjustment'
                    ), 0) AS adjustment_debt_change,
                    COALESCE(SUM(points), 0) AS debt_net
             FROM loyalty_debt_ledger
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY 1
             ORDER BY 1",
            $this->rangeParams($context)
        );
        $indexed = [];
        foreach ($raw as $row) {
            $indexed[(string)$row['bucket']] = $row;
        }
        $debtIndexed = [];
        foreach ($debtRaw as $row) {
            $debtIndexed[(string)$row['bucket']] = $row;
        }

        $result = [];
        foreach ($this->bucketLabels($context) as $bucket) {
            $row = $indexed[$bucket] ?? [];
            $debt = $debtIndexed[$bucket] ?? [];
            $reversalDebtCreated = (int)($debt['reversal_debt_created'] ?? 0);
            $adjustmentDebtChange = (int)($debt['adjustment_debt_change'] ?? 0);
            $debtNet = (int)($debt['debt_net'] ?? 0);
            $result[] = [
                'bucket' => $bucket,
                'purchases' => (int)($row['purchases'] ?? 0),
                'purchasePoints' => (int)($row['purchase_points'] ?? 0),
                'redemptionPoints' => (int)($row['redemption_points'] ?? 0),
                'reversalPoints' => (int)($row['reversal_points'] ?? 0) + $reversalDebtCreated,
                'reversalDebtCreated' => $reversalDebtCreated,
                'adjustmentPoints' => (int)($row['adjustment_points'] ?? 0) - $adjustmentDebtChange,
                'netPoints' => (int)($row['net_points'] ?? 0) - $debtNet,
            ];
        }

        return $result;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function executiveTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        $rows = $this->executiveTrend($context);
        if ($sort === '__exportKey') {
            $cursor = $filters['__exportCursor']['bucket'] ?? null;
            if (is_string($cursor)) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => strcmp((string)$row['bucket'], $cursor) > 0
                ));
            }
        } elseif ($sort !== 'bucket') {
            usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
                $comparison = ((int)$left[$sort]) <=> ((int)$right[$sort]);
                return $direction === 'desc' ? -$comparison : $comparison;
            });
        } elseif ($direction === 'desc') {
            $rows = array_reverse($rows);
        }
        $total = $this->knownExportRowCount ?? count($rows);

        return ['rows' => $countOnly ? [] : array_slice($rows, $offset, $limit), 'total' => $total];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function activityOverview(array $context): array
    {
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $raw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', created_at), '{$format}') AS bucket,
                    COALESCE(SUM(GREATEST(points, 0)), 0) AS credits,
                    ABS(COALESCE(SUM(LEAST(points, 0)), 0)) AS debits,
                    COALESCE(SUM(points), 0) AS net,
                    COUNT(*) AS movements
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY 1 ORDER BY 1",
            $this->rangeParams($context)
        );
        $indexed = [];
        foreach ($raw as $row) {
            $indexed[(string)$row['bucket']] = $row;
        }
        $rows = [];
        $totals = ['credits' => 0, 'debits' => 0, 'net' => 0, 'movements' => 0];
        foreach ($this->bucketLabels($context) as $bucket) {
            $source = $indexed[$bucket] ?? [];
            $row = [
                'bucket' => $bucket,
                'credits' => (int)($source['credits'] ?? 0),
                'debits' => (int)($source['debits'] ?? 0),
                'net' => (int)($source['net'] ?? 0),
                'movements' => (int)($source['movements'] ?? 0),
            ];
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
            $rows[] = $row;
        }

        $byTypeRaw = $this->fetchAll(
            "SELECT entry_type,
                    COALESCE(SUM(GREATEST(points, 0)), 0) AS credits,
                    ABS(COALESCE(SUM(LEAST(points, 0)), 0)) AS debits
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY entry_type
             ORDER BY entry_type",
            $this->rangeParams($context)
        );
        $debtByTypeRaw = $this->fetchAll(
            "SELECT entry_type,
                    COALESCE(SUM(GREATEST(points, 0)), 0) AS created,
                    ABS(COALESCE(SUM(LEAST(points, 0)), 0)) AS paid
             FROM loyalty_debt_ledger
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY entry_type
             ORDER BY entry_type",
            $this->rangeParams($context)
        );
        $debtCreated = array_sum(array_map(static fn(array $row): int => (int)$row['created'], $debtByTypeRaw));
        $debtPaid = array_sum(array_map(static fn(array $row): int => (int)$row['paid'], $debtByTypeRaw));

        $charts = [[
            'key' => 'point-activity-flow',
            'title' => 'Creditos y debitos',
            'type' => 'bar',
            'granularity' => $context['grain'],
            'categories' => array_column($rows, 'bucket'),
            'series' => [
                $this->series('credits', 'Creditos', array_column($rows, 'credits')),
                $this->series('debits', 'Debitos', array_column($rows, 'debits')),
            ],
            'unit' => 'points',
            'summary' => sprintf(
                '%d movimientos generaron %d creditos y %d debitos, para un neto disponible de %d puntos.',
                $totals['movements'], $totals['credits'], $totals['debits'], $totals['net']
            ),
        ]];
        if ($byTypeRaw !== []) {
            $charts[] = [
                'key' => 'point-activity-by-type',
                'title' => 'Creditos y debitos por tipo',
                'type' => 'bar',
                'granularity' => 'category',
                'categories' => array_map(static fn(array $row): string => (string)$row['entry_type'], $byTypeRaw),
                'series' => [
                    $this->series('credits', 'Creditos', array_map(static fn(array $row): int => (int)$row['credits'], $byTypeRaw)),
                    $this->series('debits', 'Debitos', array_map(static fn(array $row): int => (int)$row['debits'], $byTypeRaw)),
                ],
                'unit' => 'points',
                'summary' => 'Distribucion de movimientos del saldo disponible por tipo canonico del ledger.',
            ];
        }
        if ($debtByTypeRaw !== []) {
            $charts[] = [
                'key' => 'debt-activity-by-type',
                'title' => 'Movimientos de deuda por tipo',
                'type' => 'bar',
                'granularity' => 'category',
                'categories' => array_map(static fn(array $row): string => (string)$row['entry_type'], $debtByTypeRaw),
                'series' => [
                    $this->series('created', 'Deuda creada', array_map(static fn(array $row): int => (int)$row['created'], $debtByTypeRaw)),
                    $this->series('paid', 'Deuda amortizada', array_map(static fn(array $row): int => (int)$row['paid'], $debtByTypeRaw)),
                ],
                'unit' => 'points',
                'summary' => sprintf('Se crearon %d puntos de deuda y se amortizaron %d en el periodo.', $debtCreated, $debtPaid),
            ];
        }

        return [
            'metrics' => [
                $this->metric('credits', 'Creditos', $totals['credits'], 'points', 'Suma de movimientos positivos.'),
                $this->metric('debits', 'Debitos', $totals['debits'], 'points', 'Valor absoluto de movimientos negativos.'),
                $this->metric('net', 'Variacion neta', $totals['net'], 'points', 'Creditos menos debitos.'),
                $this->metric('movements', 'Movimientos', $totals['movements'], 'integer', 'Cantidad de entradas del ledger.'),
                $this->metric('debtCreated', 'Deuda creada', $debtCreated, 'points', 'Incrementos registrados en el ledger de deuda.'),
                $this->metric('debtPaid', 'Deuda amortizada', $debtPaid, 'points', 'Reducciones registradas en el ledger de deuda.'),
            ],
            'charts' => $charts,
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function activityTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        $where = [];
        $params = $this->rangeParams($context);
        $entryType = trim((string)($filters['entryType'] ?? ''));
        if ($entryType !== '') {
            $where[] = 'activity.entry_type = :entry_type';
            $params['entry_type'] = $entryType;
        }
        $source = trim((string)($filters['source'] ?? ''));
        if ($source !== '') {
            $where[] = 'activity.source = :source';
            $params['source'] = $source;
        }
        $accountId = trim((string)($filters['accountId'] ?? ''));
        if ($accountId !== '') {
            $where[] = 'activity.account_id = :account_id';
            $params['account_id'] = $accountId;
        }
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $where[] = '(activity.ledger_kind, activity.id) > (:cursor_ledger_kind, :cursor_id)';
            $params['cursor_ledger_kind'] = (string)($exportCursor['ledgerKind'] ?? '');
            $params['cursor_id'] = (string)($exportCursor['id'] ?? '');
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $base = "WITH activity AS (
                    SELECT l.id, l.created_at, m.account_id, m.account_name,
                           'points'::text AS ledger_kind, l.entry_type, l.points,
                           l.balance_after, l.reference, l.source, l.created_by_user_id,
                           l.metadata, l.sequence_no
                    FROM loyalty_point_ledger l
                    JOIN loyalty_members m ON m.tenant_id = l.tenant_id AND m.id = l.member_id
                    WHERE l.tenant_id = :tenant_id
                      AND l.created_at >= :from_start
                      AND l.created_at < :to_exclusive
                    UNION ALL
                    SELECT d.id, d.created_at, m.account_id, m.account_name,
                           'debt'::text AS ledger_kind, d.entry_type, d.points,
                           d.debt_after AS balance_after, d.reference, d.source, d.created_by_user_id,
                           d.metadata, d.sequence_no
                    FROM loyalty_debt_ledger d
                    JOIN loyalty_members m ON m.tenant_id = d.tenant_id AND m.id = d.member_id
                    WHERE d.tenant_id = :tenant_id
                      AND d.created_at >= :from_start
                      AND d.created_at < :to_exclusive
                )";
        $total = $this->knownExportRowCount ?? (int)$this->scalar(
            "{$base} SELECT COUNT(*) FROM activity {$whereSql}",
            $params
        );
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'createdAt' => 'activity.created_at',
            'accountId' => 'activity.account_id',
            'accountName' => 'activity.account_name',
            'ledgerKind' => 'activity.ledger_kind',
            'entryType' => 'activity.entry_type',
            'points' => 'activity.points',
            'balanceAfter' => 'activity.balance_after',
            'source' => 'activity.source',
            'actor' => 'activity.created_by_user_id',
        ];
        $order = $sortMap[$sort] ?? 'activity.created_at';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'activity.ledger_kind ASC, activity.id ASC'
            : "{$order} {$sqlDirection}, activity.created_at {$sqlDirection}, activity.ledger_kind {$sqlDirection}, activity.sequence_no {$sqlDirection}, activity.id {$sqlDirection}";
        $rows = $this->fetchAll(
            "{$base}
             SELECT activity.id,
                    to_char(activity.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at,
                    activity.account_id, activity.account_name, activity.ledger_kind,
                    activity.entry_type, activity.points, activity.balance_after,
                    activity.reference, activity.source, activity.created_by_user_id,
                    COALESCE(activity.metadata->>'reason', '') AS reason,
                    COALESCE(activity.metadata->>'evidence', activity.metadata->>'evidenceReference', '') AS evidence
             FROM activity
             {$whereSql}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'id' => (string)$row['id'],
                'createdAt' => (string)$row['created_at'],
                'accountId' => (string)$row['account_id'],
                'accountName' => (string)$row['account_name'],
                'ledgerKind' => (string)$row['ledger_kind'],
                'entryType' => (string)$row['entry_type'],
                'points' => (int)$row['points'],
                'balanceAfter' => (int)$row['balance_after'],
                'reference' => (string)($row['reference'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'actor' => (string)($row['created_by_user_id'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'evidence' => (string)($row['evidence'] ?? ''),
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function membersOverview(array $context): array
    {
        $params = $this->rangeParams($context);
        $current = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id',
            ['tenant_id' => $context['tenantId']]
        );
        $new = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_members
             WHERE tenant_id = :tenant_id AND created_at >= :from_start AND created_at < :to_exclusive',
            $params
        );
        $active = (int)$this->scalar(
            'SELECT COUNT(DISTINCT member_id) FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id AND created_at >= :from_start AND created_at < :to_exclusive',
            $params
        );
        $balance = (int)$this->scalar(
            'SELECT COALESCE(SUM(balance), 0) FROM loyalty_point_accounts WHERE tenant_id = :tenant_id',
            ['tenant_id' => $context['tenantId']]
        );
        $newRows = $this->timeCountRows('loyalty_members', 'created_at', $context);
        $tierRows = $this->fetchAll(
            'SELECT tier, COUNT(*) AS total
             FROM loyalty_members
             WHERE tenant_id = :tenant_id
             GROUP BY tier ORDER BY total DESC, tier',
            ['tenant_id' => $context['tenantId']]
        );

        return [
            'metrics' => [
                $this->metric('currentMembers', 'Socios actuales', $current, 'integer', 'Foto actual de todos los socios.'),
                $this->metric('newMembers', 'Altas del periodo', $new, 'integer', 'Socios creados dentro del periodo.'),
                $this->metric('activeMembers', 'Socios con actividad', $active, 'integer', 'Socios con al menos un movimiento en el periodo.'),
                $this->metric('currentBalance', 'Saldo actual', $balance, 'points', 'Saldo disponible agregado en la foto actual.'),
            ],
            'charts' => [
                [
                    'key' => 'member-signups',
                    'title' => 'Altas de socios',
                    'type' => 'line',
                    'granularity' => $context['grain'],
                    'categories' => array_column($newRows, 'bucket'),
                    'series' => [$this->series('new-members', 'Nuevos socios', array_column($newRows, 'total'))],
                    'unit' => 'count',
                    'summary' => sprintf('Se registraron %d socios nuevos durante el periodo.', $new),
                ],
                [
                    'key' => 'current-tier-distribution',
                    'title' => 'Distribucion actual por nivel',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['tier'], $tierRows),
                    'series' => [$this->series(
                        'members',
                        'Socios',
                        array_map(static fn(array $row): int => (int)$row['total'], $tierRows)
                    )],
                    'unit' => 'count',
                    'summary' => 'La distribucion por nivel es una foto actual, no una reconstruccion historica.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function membersTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        $params = $this->rangeParams($context);
        $where = ['m.tenant_id = :tenant_id'];
        $tier = trim((string)($filters['tier'] ?? ''));
        if ($tier !== '') {
            $where[] = 'm.tier = :tier';
            $params['tier'] = $tier;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'm.status = :status';
            $params['status'] = $status;
        }
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $where[] = "(m.tier, m.status, COALESCE(m.wallet_platform, '')) > (:cursor_tier, :cursor_status, :cursor_wallet_platform)";
            $params['cursor_tier'] = (string)($exportCursor['tier'] ?? '');
            $params['cursor_status'] = (string)($exportCursor['status'] ?? '');
            $params['cursor_wallet_platform'] = (string)($exportCursor['walletPlatform'] ?? '');
        }
        $whereSql = implode(' AND ', $where);
        $base = "SELECT m.tier, m.status, m.wallet_platform,
                        COUNT(*) AS current_members,
                        COUNT(*) FILTER (WHERE m.created_at >= :from_start AND m.created_at < :to_exclusive) AS new_members,
                        COUNT(*) FILTER (WHERE EXISTS (
                            SELECT 1 FROM loyalty_point_ledger l
                            WHERE l.tenant_id = m.tenant_id AND l.member_id = m.id
                              AND l.created_at >= :from_start AND l.created_at < :to_exclusive
                        )) AS active_members,
                        COALESCE(SUM(a.balance), 0) AS current_balance
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.tenant_id = m.tenant_id AND a.member_id = m.id
                 WHERE {$whereSql}
                 GROUP BY m.tier, m.status, m.wallet_platform";
        $total = $this->knownExportRowCount ?? (int)$this->scalar("SELECT COUNT(*) FROM ({$base}) grouped", $params);
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'tier' => 'tier',
            'status' => 'status',
            'walletPlatform' => 'wallet_platform',
            'currentMembers' => 'current_members',
            'newMembers' => 'new_members',
            'activeMembers' => 'active_members',
            'currentBalance' => 'current_balance',
        ];
        $order = $sortMap[$sort] ?? 'current_members';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? "tier ASC, status ASC, COALESCE(wallet_platform, '') ASC"
            : "{$order} {$sqlDirection}, tier ASC, status ASC, wallet_platform ASC";
        $rows = $this->fetchAll(
            "SELECT * FROM ({$base}) grouped
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'tier' => (string)$row['tier'],
                'status' => (string)$row['status'],
                'walletPlatform' => (string)$row['wallet_platform'],
                'currentMembers' => (int)$row['current_members'],
                'newMembers' => (int)$row['new_members'],
                'activeMembers' => (int)$row['active_members'],
                'currentBalance' => (int)$row['current_balance'],
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function cardsOverview(array $context): array
    {
        $params = $this->rangeParams($context);
        $members = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id',
            ['tenant_id' => $context['tenantId']]
        );
        $passes = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_wallet_passes WHERE tenant_id = :tenant_id',
            ['tenant_id' => $context['tenantId']]
        );
        $holders = (int)$this->scalar(
            'SELECT COUNT(DISTINCT member_id) FROM loyalty_wallet_passes WHERE tenant_id = :tenant_id',
            ['tenant_id' => $context['tenantId']]
        );
        $created = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_wallet_passes
             WHERE tenant_id = :tenant_id AND created_at >= :from_start AND created_at < :to_exclusive',
            $params
        );
        $without = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_members m
             WHERE m.tenant_id = :tenant_id
               AND NOT EXISTS (
                   SELECT 1 FROM loyalty_wallet_passes p
                   WHERE p.tenant_id = m.tenant_id AND p.member_id = m.id
               )',
            ['tenant_id' => $context['tenantId']]
        );
        $adoption = $members > 0 ? round(($holders / $members) * 100, 2) : 0.0;
        $createdRows = $this->timeCountRows('loyalty_wallet_passes', 'created_at', $context);
        $distribution = $this->fetchAll(
            "SELECT platform, status, COUNT(*) AS total
             FROM loyalty_wallet_passes
             WHERE tenant_id = :tenant_id
             GROUP BY platform, status
             ORDER BY total DESC, platform, status",
            ['tenant_id' => $context['tenantId']]
        );

        return [
            'metrics' => [
                $this->metric('currentPasses', 'Tarjetas actuales', $passes, 'integer', 'Pases Wallet existentes en la foto actual.'),
                $this->metric('createdInPeriod', 'Creadas en el periodo', $created, 'integer', 'Pases creados durante el periodo.'),
                $this->metric('membersWithoutPass', 'Socios sin tarjeta', $without, 'integer', 'Socios que actualmente no tienen pase.'),
                $this->metric('adoption', 'Adopcion', $adoption, 'percent', 'Socios con al menos una tarjeta divididos para socios actuales.'),
            ],
            'charts' => [
                [
                    'key' => 'wallet-pass-signups',
                    'title' => 'Nuevas tarjetas',
                    'type' => 'line',
                    'granularity' => $context['grain'],
                    'categories' => array_column($createdRows, 'bucket'),
                    'series' => [$this->series('passes', 'Tarjetas creadas', array_column($createdRows, 'total'))],
                    'unit' => 'count',
                    'summary' => sprintf('Se crearon %d tarjetas digitales durante el periodo.', $created),
                ],
                [
                    'key' => 'wallet-current-distribution',
                    'title' => 'Distribucion actual de tarjetas',
                    'type' => 'bar',
                    'categories' => array_map(
                        static fn(array $row): string => (string)$row['platform'] . ' / ' . (string)$row['status'],
                        $distribution
                    ),
                    'series' => [$this->series(
                        'passes',
                        'Tarjetas',
                        array_map(static fn(array $row): int => (int)$row['total'], $distribution)
                    )],
                    'unit' => 'count',
                    'summary' => 'La plataforma y el estado corresponden a la foto actual del sistema.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function cardsTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        $params = $this->rangeParams($context);
        $where = ['p.tenant_id = :tenant_id'];
        $platform = trim((string)($filters['platform'] ?? ''));
        if ($platform !== '') {
            $where[] = 'p.platform = :platform';
            $params['platform'] = $platform;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $where[] = "(COALESCE(p.platform, ''), COALESCE(p.status, '')) > (:cursor_platform, :cursor_status)";
            $params['cursor_platform'] = (string)($exportCursor['platform'] ?? '');
            $params['cursor_status'] = (string)($exportCursor['status'] ?? '');
        }
        $whereSql = implode(' AND ', $where);
        $base = "SELECT p.platform, p.status,
                        COUNT(*) AS current_passes,
                        COUNT(*) FILTER (WHERE p.created_at >= :from_start AND p.created_at < :to_exclusive) AS created_in_period
                 FROM loyalty_wallet_passes p
                 WHERE {$whereSql}
                 GROUP BY p.platform, p.status";
        $total = $this->knownExportRowCount ?? (int)$this->scalar("SELECT COUNT(*) FROM ({$base}) grouped", $params);
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'platform' => 'platform',
            'status' => 'status',
            'currentPasses' => 'current_passes',
            'createdInPeriod' => 'created_in_period',
        ];
        $order = $sortMap[$sort] ?? 'current_passes';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? "COALESCE(platform, '') ASC, COALESCE(status, '') ASC"
            : "{$order} {$sqlDirection}, platform ASC, status ASC";
        $rows = $this->fetchAll(
            "SELECT * FROM ({$base}) grouped
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'platform' => (string)$row['platform'],
                'status' => (string)$row['status'],
                'currentPasses' => (int)$row['current_passes'],
                'createdInPeriod' => (int)$row['created_in_period'],
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function redemptionsOverview(array $context): array
    {
        $params = $this->rangeParams($context);
        $summary = $this->fetchAll(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                    COUNT(*) FILTER (WHERE status IN ('cancelled', 'expired')) AS released,
                    COALESCE(SUM(points_cost) FILTER (WHERE status NOT IN ('cancelled', 'expired')), 0) AS gross_points
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive",
            $params
        )[0] ?? [];
        $restored = (int)$this->scalar(
            "SELECT COALESCE(SUM(points), 0)
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND entry_type = 'redemption_reversal'
               AND created_at >= :from_start
               AND created_at < :to_exclusive",
            $params
        );
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $createdRaw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', created_at), '{$format}') AS bucket,
                    COUNT(*) AS redemptions,
                    COALESCE(SUM(points_cost), 0) AS points
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY 1 ORDER BY 1",
            $params
        );
        $refundRaw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', created_at), '{$format}') AS bucket,
                    COALESCE(SUM(points), 0) AS restored
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND entry_type = 'redemption_reversal'
               AND created_at >= :from_start
               AND created_at < :to_exclusive
             GROUP BY 1 ORDER BY 1",
            $params
        );
        $createdByBucket = [];
        foreach ($createdRaw as $row) {
            $createdByBucket[(string)$row['bucket']] = $row;
        }
        $refundByBucket = [];
        foreach ($refundRaw as $row) {
            $refundByBucket[(string)$row['bucket']] = $row;
        }
        $trend = [];
        foreach ($this->bucketLabels($context) as $bucket) {
            $trend[] = [
                'bucket' => $bucket,
                'redemptions' => (int)($createdByBucket[$bucket]['redemptions'] ?? 0),
                'points' => (int)($createdByBucket[$bucket]['points'] ?? 0),
                'restored' => (int)($refundByBucket[$bucket]['restored'] ?? 0),
            ];
        }
        $top = $this->fetchAll(
            "SELECT w.name AS reward, COUNT(r.id) AS total
             FROM loyalty_redemptions r
             JOIN loyalty_rewards w ON w.tenant_id = r.tenant_id AND w.id = r.reward_id
             WHERE r.tenant_id = :tenant_id
               AND r.created_at >= :from_start
               AND r.created_at < :to_exclusive
             GROUP BY w.name
             ORDER BY total DESC, w.name
             LIMIT 10",
            $params
        );

        return [
            'metrics' => [
                $this->metric('redemptions', 'Canjes creados', (int)($summary['total'] ?? 0), 'integer', 'Solicitudes y canjes creados en el periodo.'),
                $this->metric('delivered', 'Entregados', (int)($summary['delivered'] ?? 0), 'integer', 'Canjes entregados entre los creados en el periodo.'),
                $this->metric('released', 'Cancelados o expirados', (int)($summary['released'] ?? 0), 'integer', 'Canjes liberados entre los creados en el periodo.'),
                $this->metric('grossPoints', 'Puntos comprometidos', (int)($summary['gross_points'] ?? 0), 'points', 'Costo bruto de canjes no cancelados ni expirados.'),
                $this->metric('restoredPoints', 'Puntos devueltos', $restored, 'points', 'Devoluciones de canje registradas en el ledger durante el periodo.'),
            ],
            'charts' => [
                [
                    'key' => 'redemption-trend',
                    'title' => 'Canjes y devoluciones',
                    'type' => 'line',
                    'granularity' => $context['grain'],
                    'categories' => array_column($trend, 'bucket'),
                    'series' => [
                        $this->series('points', 'Puntos solicitados', array_column($trend, 'points')),
                        $this->series('restored', 'Puntos devueltos', array_column($trend, 'restored')),
                    ],
                    'unit' => 'points',
                    'summary' => sprintf(
                        'Los canjes del periodo comprometieron %d puntos y el ledger devolvio %d puntos.',
                        (int)($summary['gross_points'] ?? 0),
                        $restored
                    ),
                ],
                [
                    'key' => 'top-rewards',
                    'title' => 'Premios mas utilizados',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['reward'], $top),
                    'series' => [$this->series(
                        'redemptions',
                        'Canjes',
                        array_map(static fn(array $row): int => (int)$row['total'], $top)
                    )],
                    'unit' => 'count',
                    'summary' => $top === []
                        ? 'No hubo premios solicitados en el periodo.'
                        : sprintf('El premio mas solicitado fue %s con %d canjes.', $top[0]['reward'], $top[0]['total']),
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function redemptionsTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        $params = $this->rangeParams($context);
        $where = [
            'r.tenant_id = :tenant_id',
            'r.created_at >= :from_start',
            'r.created_at < :to_exclusive',
        ];
        foreach (['status' => 'r.status', 'rewardId' => 'r.reward_id', 'source' => 'r.source'] as $filter => $column) {
            $value = trim((string)($filters[$filter] ?? ''));
            if ($value !== '') {
                $parameter = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $filter) ?? $filter);
                $where[] = "{$column} = :{$parameter}";
                $params[$parameter] = $value;
            }
        }
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $where[] = 'r.id > :cursor_id';
            $params['cursor_id'] = (string)($exportCursor['id'] ?? '');
        }
        $whereSql = implode(' AND ', $where);
        $total = $this->knownExportRowCount ?? (int)$this->scalar(
            "SELECT COUNT(*)
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.tenant_id = r.tenant_id AND m.id = r.member_id
             JOIN loyalty_rewards w ON w.tenant_id = r.tenant_id AND w.id = r.reward_id
             WHERE {$whereSql}",
            $params
        );
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'createdAt' => 'r.created_at',
            'accountId' => 'm.account_id',
            'accountName' => 'm.account_name',
            'reward' => 'w.name',
            'points' => 'r.points_cost',
            'source' => 'r.source',
            'fulfillment' => 'r.fulfillment_type',
            'status' => 'r.status',
            'resolvedAt' => 'r.resolved_at',
            'actor' => 'r.resolved_by_user_id',
            'restoredPoints' => 'restored.restored_points',
            'currentStock' => 'w.stock',
        ];
        $order = $sortMap[$sort] ?? 'r.created_at';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'r.id ASC'
            : "{$order} {$sqlDirection} NULLS LAST, r.created_at {$sqlDirection}, r.id {$sqlDirection}";
        $rows = $this->fetchAll(
            "SELECT r.id, to_char(r.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at,
                    m.account_id, m.account_name, w.name AS reward, r.points_cost,
                    r.source, COALESCE(r.fulfillment_type, w.claim_mode, '') AS fulfillment,
                    r.status,
                    CASE WHEN r.resolved_at IS NULL THEN NULL ELSE to_char(r.resolved_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') END AS resolved_at,
                    r.resolved_by_user_id, r.resolution_note, w.stock,
                    COALESCE(restored.restored_points, 0) AS restored_points
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.tenant_id = r.tenant_id AND m.id = r.member_id
             JOIN loyalty_rewards w ON w.tenant_id = r.tenant_id AND w.id = r.reward_id
             LEFT JOIN LATERAL (
                 SELECT COALESCE(SUM(l.points), 0) AS restored_points
                 FROM loyalty_point_ledger l
                 WHERE l.tenant_id = r.tenant_id
                   AND l.entry_type = 'redemption_reversal'
                   AND (l.source_reference = r.id OR l.reference = r.id)
             ) restored ON TRUE
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'id' => (string)$row['id'],
                'createdAt' => (string)$row['created_at'],
                'accountId' => (string)$row['account_id'],
                'accountName' => (string)$row['account_name'],
                'reward' => (string)$row['reward'],
                'points' => (int)$row['points_cost'],
                'source' => (string)$row['source'],
                'fulfillment' => (string)$row['fulfillment'],
                'status' => (string)$row['status'],
                'resolvedAt' => $row['resolved_at'] === null ? null : (string)$row['resolved_at'],
                'actor' => (string)($row['resolved_by_user_id'] ?? ''),
                'resolution' => (string)($row['resolution_note'] ?? ''),
                'restoredPoints' => (int)$row['restored_points'],
                'currentStock' => (int)$row['stock'],
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function riskOverview(array $context, array $filters): array
    {
        [$whereSql, $params] = $this->riskWhere($context, $filters);
        $summary = $this->fetchAll(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'open') AS open,
                    COUNT(*) FILTER (WHERE status = 'resolved') AS resolved,
                    COUNT(*) FILTER (WHERE severity IN ('critical', 'high')) AS high_or_critical
             FROM loyalty_risk_events r
             WHERE {$whereSql}",
            $params
        )[0] ?? [];
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $trend = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', r.created_at), '{$format}') AS bucket,
                    r.severity, COUNT(*) AS total
             FROM loyalty_risk_events r
             WHERE {$whereSql}
             GROUP BY 1, r.severity ORDER BY 1, r.severity",
            $params
        );
        $types = $this->fetchAll(
            "SELECT r.event_type, COUNT(*) AS total
             FROM loyalty_risk_events r
             WHERE {$whereSql}
             GROUP BY r.event_type ORDER BY total DESC, r.event_type LIMIT 10",
            $params
        );
        $severityChart = $this->categoricalTimeChart(
            'risk-severity',
            'Eventos por severidad',
            $context,
            $trend,
            'severity',
            'total',
            'count',
            'Los eventos se separan por severidad; la etiqueta y la leyenda evitan depender solo del color.'
        );

        return [
            'metrics' => [
                $this->metric('events', 'Eventos', (int)($summary['total'] ?? 0), 'integer', 'Eventos que cumplen los filtros aplicados.'),
                $this->metric('open', 'Abiertos', (int)($summary['open'] ?? 0), 'integer', 'Eventos pendientes de resolucion.'),
                $this->metric('resolved', 'Resueltos', (int)($summary['resolved'] ?? 0), 'integer', 'Eventos resueltos.'),
                $this->metric('highOrCritical', 'Altos o criticos', (int)($summary['high_or_critical'] ?? 0), 'integer', 'Eventos de severidad high o critical.'),
            ],
            'charts' => [
                $severityChart,
                [
                    'key' => 'risk-types',
                    'title' => 'Principales tipos de riesgo',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['event_type'], $types),
                    'series' => [$this->series('events', 'Eventos', array_map(static fn(array $row): int => (int)$row['total'], $types))],
                    'unit' => 'count',
                    'summary' => $types === [] ? 'No se registraron eventos de riesgo.' : 'Se muestran hasta diez tipos, ordenados por frecuencia.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function riskTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        [$whereSql, $params] = $this->riskWhere($context, $filters);
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $whereSql .= ' AND r.id > :cursor_id';
            $params['cursor_id'] = (string)($exportCursor['id'] ?? '');
        }
        $total = $this->knownExportRowCount ?? (int)$this->scalar("SELECT COUNT(*) FROM loyalty_risk_events r WHERE {$whereSql}", $params);
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'createdAt' => 'r.created_at',
            'severity' => 'r.severity',
            'eventType' => 'r.event_type',
            'status' => 'r.status',
            'accountId' => 'm.account_id',
            'resolvedAt' => 'r.resolved_at',
            'resolvedBy' => 'r.resolved_by_user_id',
        ];
        $order = $sortMap[$sort] ?? 'r.created_at';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'r.id ASC'
            : "{$order} {$sqlDirection} NULLS LAST, r.created_at {$sqlDirection}, r.id {$sqlDirection}";
        $rows = $this->fetchAll(
            "SELECT r.id, to_char(r.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at,
                    r.severity, r.event_type, r.status, COALESCE(m.account_id, '') AS account_id,
                    r.reference, r.message, r.metadata,
                    CASE WHEN r.resolved_at IS NULL THEN NULL ELSE to_char(r.resolved_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') END AS resolved_at,
                    r.resolved_by_user_id
             FROM loyalty_risk_events r
             LEFT JOIN loyalty_members m ON m.tenant_id = r.tenant_id AND m.id = r.member_id
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                'id' => (string)$row['id'],
                'createdAt' => (string)$row['created_at'],
                'severity' => (string)$row['severity'],
                'eventType' => (string)$row['event_type'],
                'status' => (string)$row['status'],
                'accountId' => (string)$row['account_id'],
                'reference' => (string)($row['reference'] ?? ''),
                'message' => (string)$row['message'],
                'metadata' => $this->safeJson($row['metadata'] ?? null),
                'resolvedAt' => $row['resolved_at'] === null ? null : (string)$row['resolved_at'],
                'resolvedBy' => (string)($row['resolved_by_user_id'] ?? ''),
            ];
        }

        return ['rows' => $mapped, 'total' => $total];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function riskWhere(array $context, array $filters): array
    {
        $where = [
            'r.tenant_id = :tenant_id',
            'r.created_at >= :from_start',
            'r.created_at < :to_exclusive',
        ];
        $params = $this->rangeParams($context);
        $severity = strtolower(trim((string)($filters['severity'] ?? '')));
        if (in_array($severity, ['critical', 'high', 'medium', 'low', 'info'], true)) {
            $where[] = 'r.severity = :severity';
            $params['severity'] = $severity;
        }
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if (in_array($status, ['open', 'resolved'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function auditOverview(array $context, array $filters): array
    {
        [$whereSql, $params] = $this->auditWhere($context, $filters);
        $summary = $this->fetchAll(
            "SELECT COUNT(*) AS total,
                    COUNT(DISTINCT COALESCE(a.actor_user_id, a.actor_type)) AS actors,
                    COUNT(DISTINCT a.action) AS actions
             FROM loyalty_audit_events a
             WHERE {$whereSql}",
            $params
        )[0] ?? [];
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $trendRaw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', a.created_at), '{$format}') AS bucket, COUNT(*) AS total
             FROM loyalty_audit_events a
             WHERE {$whereSql}
             GROUP BY 1 ORDER BY 1",
            $params
        );
        $trendIndex = [];
        foreach ($trendRaw as $row) {
            $trendIndex[(string)$row['bucket']] = (int)$row['total'];
        }
        $trend = [];
        foreach ($this->bucketLabels($context) as $bucket) {
            $trend[] = ['bucket' => $bucket, 'total' => $trendIndex[$bucket] ?? 0];
        }
        $actions = $this->fetchAll(
            "SELECT a.action, COUNT(*) AS total
             FROM loyalty_audit_events a
             WHERE {$whereSql}
             GROUP BY a.action ORDER BY total DESC, a.action LIMIT 10",
            $params
        );
        $actors = $this->fetchAll(
            "SELECT COALESCE(NULLIF(a.actor_user_id, ''), a.actor_type) AS actor, COUNT(*) AS total
             FROM loyalty_audit_events a
             WHERE {$whereSql}
             GROUP BY 1 ORDER BY total DESC, actor LIMIT 10",
            $params
        );

        return [
            'metrics' => [
                $this->metric('events', 'Eventos', (int)($summary['total'] ?? 0), 'integer', 'Acciones de auditoria que cumplen los filtros.'),
                $this->metric('actors', 'Actores', (int)($summary['actors'] ?? 0), 'integer', 'Actores distintos observados.'),
                $this->metric('actions', 'Acciones distintas', (int)($summary['actions'] ?? 0), 'integer', 'Tipos de accion distintos observados.'),
            ],
            'charts' => [
                [
                    'key' => 'audit-trend',
                    'title' => 'Acciones por periodo',
                    'type' => 'line',
                    'granularity' => $context['grain'],
                    'categories' => array_column($trend, 'bucket'),
                    'series' => [$this->series('events', 'Acciones', array_column($trend, 'total'))],
                    'unit' => 'count',
                    'summary' => sprintf('Se registraron %d acciones de auditoria.', (int)($summary['total'] ?? 0)),
                ],
                [
                    'key' => 'audit-actions',
                    'title' => 'Principales acciones',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['action'], $actions),
                    'series' => [$this->series('events', 'Eventos', array_map(static fn(array $row): int => (int)$row['total'], $actions))],
                    'unit' => 'count',
                    'summary' => 'Se muestran hasta diez acciones, ordenadas por frecuencia.',
                ],
                [
                    'key' => 'audit-actors',
                    'title' => 'Principales actores',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['actor'], $actors),
                    'series' => [$this->series('events', 'Acciones', array_map(static fn(array $row): int => (int)$row['total'], $actors))],
                    'unit' => 'count',
                    'summary' => 'La clasificacion usa el identificador del actor o su tipo cuando no existe usuario.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function auditTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        [$whereSql, $params] = $this->auditWhere($context, $filters);
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $whereSql .= ' AND a.id > :cursor_id';
            $params['cursor_id'] = (string)($exportCursor['id'] ?? '');
        }
        $total = $this->knownExportRowCount ?? (int)$this->scalar("SELECT COUNT(*) FROM loyalty_audit_events a WHERE {$whereSql}", $params);
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'createdAt' => 'a.created_at',
            'actor' => 'a.actor_user_id',
            'actorType' => 'a.actor_type',
            'action' => 'a.action',
            'subjectType' => 'a.subject_type',
        ];
        $order = $sortMap[$sort] ?? 'a.created_at';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'a.id ASC'
            : "{$order} {$sqlDirection} NULLS LAST, a.created_at {$sqlDirection}, a.id {$sqlDirection}";
        $rows = $this->fetchAll(
            "SELECT a.id, to_char(a.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at,
                    a.actor_user_id, a.actor_type, a.action, a.subject_type, a.subject_id,
                    a.reason, a.before_state, a.after_state, a.metadata
             FROM loyalty_audit_events a
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                'id' => (string)$row['id'],
                'createdAt' => (string)$row['created_at'],
                'actor' => (string)($row['actor_user_id'] ?? ''),
                'actorType' => (string)$row['actor_type'],
                'action' => (string)$row['action'],
                'subjectType' => (string)$row['subject_type'],
                'subjectId' => (string)($row['subject_id'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'before' => $this->safeJson($row['before_state'] ?? null),
                'after' => $this->safeJson($row['after_state'] ?? null),
                'metadata' => $this->safeJson($row['metadata'] ?? null),
            ];
        }

        return ['rows' => $mapped, 'total' => $total];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function auditWhere(array $context, array $filters): array
    {
        $where = [
            'a.tenant_id = :tenant_id',
            'a.created_at >= :from_start',
            'a.created_at < :to_exclusive',
        ];
        $params = $this->rangeParams($context);
        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }
        $actor = trim((string)($filters['actor'] ?? ''));
        if ($actor !== '') {
            $where[] = 'a.actor_user_id = :actor';
            $params['actor'] = $actor;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function apiOverview(array $context, array $filters): array
    {
        [$whereSql, $params] = $this->apiWhere($context, $filters);
        $summary = $this->fetchAll(
            "SELECT COALESCE(SUM(u.request_count), 0) AS requests,
                    COUNT(DISTINCT u.api_client_id) AS clients,
                    COUNT(DISTINCT u.usage_date) AS active_days
             FROM loyalty_api_usage_daily u
             JOIN loyalty_api_clients c ON c.tenant_id = u.tenant_id AND c.id = u.api_client_id
             WHERE {$whereSql}",
            $params
        )[0] ?? [];
        $daily = $this->fetchAll(
            "SELECT u.usage_date::text AS bucket, SUM(u.request_count) AS total
             FROM loyalty_api_usage_daily u
             JOIN loyalty_api_clients c ON c.tenant_id = u.tenant_id AND c.id = u.api_client_id
             WHERE {$whereSql}
             GROUP BY u.usage_date ORDER BY u.usage_date",
            $params
        );
        $dailyIndex = [];
        foreach ($daily as $row) {
            $dailyIndex[(string)$row['bucket']] = (int)$row['total'];
        }
        $trend = [];
        foreach ($this->bucketLabels($context, true) as $bucket) {
            $date = substr($bucket, 0, 10);
            $trend[] = ['bucket' => $date, 'total' => $dailyIndex[$date] ?? 0];
        }
        $clients = $this->fetchAll(
            "SELECT c.name, SUM(u.request_count) AS total
             FROM loyalty_api_usage_daily u
             JOIN loyalty_api_clients c ON c.tenant_id = u.tenant_id AND c.id = u.api_client_id
             WHERE {$whereSql}
             GROUP BY c.id, c.name ORDER BY total DESC, c.name LIMIT 10",
            $params
        );

        return [
            'metrics' => [
                $this->metric('requests', 'Solicitudes', (int)($summary['requests'] ?? 0), 'integer', 'Solicitudes contabilizadas para los filtros aplicados.'),
                $this->metric('clients', 'Clientes con uso', (int)($summary['clients'] ?? 0), 'integer', 'Credenciales distintas que registraron solicitudes.'),
                $this->metric('activeDays', 'Dias con actividad', (int)($summary['active_days'] ?? 0), 'integer', 'Dias locales con al menos una solicitud.'),
            ],
            'charts' => [
                [
                    'key' => 'api-daily',
                    'title' => 'Solicitudes diarias',
                    'type' => 'line',
                    'granularity' => 'day',
                    'categories' => array_column($trend, 'bucket'),
                    'series' => [$this->series('requests', 'Solicitudes', array_column($trend, 'total'))],
                    'unit' => 'count',
                    'summary' => sprintf('Se contabilizaron %d solicitudes en %d dias con actividad.', (int)($summary['requests'] ?? 0), (int)($summary['active_days'] ?? 0)),
                ],
                [
                    'key' => 'api-clients',
                    'title' => 'Consumo por cliente',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['name'], $clients),
                    'series' => [$this->series('requests', 'Solicitudes', array_map(static fn(array $row): int => (int)$row['total'], $clients))],
                    'unit' => 'count',
                    'summary' => 'Se muestran hasta diez clientes, ordenados por consumo.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function apiTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        [$whereSql, $params] = $this->apiWhere($context, $filters);
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $whereSql .= ' AND (u.usage_date, c.id) > (:cursor_date, :cursor_client_id)';
            $params['cursor_date'] = (string)($exportCursor['date'] ?? '');
            $params['cursor_client_id'] = (string)($exportCursor['clientId'] ?? '');
        }
        $total = $this->knownExportRowCount ?? (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_api_usage_daily u
             JOIN loyalty_api_clients c ON c.tenant_id = u.tenant_id AND c.id = u.api_client_id
             WHERE {$whereSql}",
            $params
        );
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'date' => 'u.usage_date',
            'client' => 'c.name',
            'source' => 'c.source',
            'status' => 'c.status',
            'requests' => 'u.request_count',
            'rateLimit' => 'c.rate_limit_per_minute',
            'lastUsedAt' => 'c.last_used_at',
        ];
        $order = $sortMap[$sort] ?? 'u.usage_date';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'u.usage_date ASC, c.id ASC'
            : "{$order} {$sqlDirection} NULLS LAST, u.usage_date {$sqlDirection}, c.id {$sqlDirection}";
        $rows = $this->fetchAll(
            "SELECT u.usage_date::text AS usage_date, c.id, c.name, c.source, c.status,
                    u.request_count, c.rate_limit_per_minute,
                    CASE WHEN c.last_used_at IS NULL THEN NULL ELSE to_char(c.last_used_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') END AS last_used_at
             FROM loyalty_api_usage_daily u
             JOIN loyalty_api_clients c ON c.tenant_id = u.tenant_id AND c.id = u.api_client_id
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'date' => (string)$row['usage_date'],
                'clientId' => (string)$row['id'],
                'client' => (string)$row['name'],
                'source' => (string)$row['source'],
                'status' => (string)$row['status'],
                'requests' => (int)$row['request_count'],
                'rateLimit' => (int)$row['rate_limit_per_minute'],
                'lastUsedAt' => $row['last_used_at'] === null ? null : (string)$row['last_used_at'],
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function apiWhere(array $context, array $filters): array
    {
        $where = [
            'u.tenant_id = :tenant_id',
            'u.usage_date >= :from_date',
            'u.usage_date <= :to_date',
        ];
        $params = [
            'tenant_id' => $context['tenantId'],
            'from_date' => $context['from'],
            'to_date' => $context['to'],
        ];
        $clientId = trim((string)($filters['clientId'] ?? ''));
        if ($clientId !== '') {
            $where[] = 'c.id = :client_id';
            $params['client_id'] = $clientId;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{metrics:array<int,array<string,mixed>>,charts:array<int,array<string,mixed>>} */
    private function reconciliationOverview(array $context, array $filters): array
    {
        [$base, $params] = $this->reconciliationBase($context);
        $summary = $this->fetchAll(
            "SELECT COUNT(*) AS accounts,
                    COUNT(*) FILTER (WHERE NOT member_missing AND NOT account_missing
                                           AND cutoff_difference = 0 AND current_difference = 0
                                           AND debt_cutoff_difference = 0 AND current_debt_difference = 0) AS correct,
                    COUNT(*) FILTER (WHERE member_missing OR account_missing
                                           OR cutoff_difference <> 0 OR current_difference <> 0
                                           OR debt_cutoff_difference <> 0 OR current_debt_difference <> 0) AS discrepancies,
                    COUNT(*) FILTER (WHERE member_missing OR account_missing) AS structural_discrepancies,
                    COALESCE(SUM(ABS(cutoff_difference) + ABS(debt_cutoff_difference)), 0) AS cutoff_absolute_difference,
                    COALESCE(SUM(ABS(current_difference) + ABS(current_debt_difference)), 0) AS current_absolute_difference
             FROM ({$base}) reconciliation",
            $params
        )[0] ?? [];
        $top = $this->fetchAll(
            "SELECT account_id, account_name,
                    GREATEST(ABS(cutoff_difference), ABS(current_difference),
                             ABS(debt_cutoff_difference), ABS(current_debt_difference)) AS difference
             FROM ({$base}) reconciliation
             WHERE member_missing OR account_missing
                OR cutoff_difference <> 0 OR current_difference <> 0
                OR debt_cutoff_difference <> 0 OR current_debt_difference <> 0
             ORDER BY difference DESC, account_id
             LIMIT 10",
            $params
        );
        $correct = (int)($summary['correct'] ?? 0);
        $discrepancies = (int)($summary['discrepancies'] ?? 0);

        return [
            'metrics' => [
                $this->metric('accounts', 'Cuentas conciliadas', (int)($summary['accounts'] ?? 0), 'integer', 'Cuentas Loyalty evaluadas al corte.'),
                $this->metric('correct', 'Cuentas correctas', $correct, 'integer', 'Cuentas sin diferencia al corte ni actual.'),
                $this->metric('discrepancies', 'Discrepancias', $discrepancies, 'integer', 'Cuentas con diferencia al corte o actual.'),
                $this->metric('cutoffAbsoluteDifference', 'Diferencia absoluta al corte', (int)($summary['cutoff_absolute_difference'] ?? 0), 'points', 'Suma absoluta de diferencias de saldo y deuda al corte.'),
                $this->metric('currentAbsoluteDifference', 'Diferencia absoluta actual', (int)($summary['current_absolute_difference'] ?? 0), 'points', 'Suma absoluta de diferencias actuales de saldo y deuda.'),
                $this->metric('structuralDiscrepancies', 'Discrepancias estructurales', (int)($summary['structural_discrepancies'] ?? 0), 'integer', 'Cuentas sin socio owner o socios sin cuenta materializada.'),
            ],
            'charts' => [
                [
                    'key' => 'reconciliation-status',
                    'title' => 'Estado de conciliacion',
                    'type' => 'donut',
                    'categories' => ['Correctas', 'Con discrepancia'],
                    'series' => [$this->series('accounts', 'Cuentas', [$correct, $discrepancies])],
                    'unit' => 'count',
                    'summary' => sprintf('%d cuentas estan correctas y %d presentan alguna discrepancia.', $correct, $discrepancies),
                ],
                [
                    'key' => 'reconciliation-largest',
                    'title' => 'Mayores diferencias',
                    'type' => 'bar',
                    'categories' => array_map(static fn(array $row): string => (string)$row['account_id'], $top),
                    'series' => [$this->series('difference', 'Diferencia absoluta', array_map(static fn(array $row): int => (int)$row['difference'], $top))],
                    'unit' => 'points',
                    'summary' => $top === [] ? 'No se detectaron diferencias.' : 'Se muestran hasta diez cuentas con mayor diferencia absoluta.',
                ],
            ],
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    private function reconciliationTable(
        array $context,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
        bool $countOnly
    ): array {
        [$base, $params] = $this->reconciliationBase($context);
        $discrepancies = strtolower(trim((string)($filters['discrepancies'] ?? 'true')));
        $onlyDiscrepancies = !in_array($discrepancies, ['0', 'false', 'all', 'no'], true);
        $conditions = [];
        if ($onlyDiscrepancies) {
            $conditions[] = '(member_missing OR account_missing OR cutoff_difference <> 0 OR current_difference <> 0 OR debt_cutoff_difference <> 0 OR current_debt_difference <> 0)';
        }
        $exportCursor = $filters['__exportCursor'] ?? null;
        if ($sort === '__exportKey' && is_array($exportCursor)) {
            $conditions[] = 'account_id > :cursor_account_id';
            $params['cursor_account_id'] = (string)($exportCursor['accountId'] ?? '');
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $total = $this->knownExportRowCount ?? (int)$this->scalar("SELECT COUNT(*) FROM ({$base}) reconciliation {$where}", $params);
        if ($countOnly) {
            return ['rows' => [], 'total' => $total];
        }
        $sortMap = [
            'accountId' => 'account_id',
            'accountName' => 'account_name',
            'integrityStatus' => 'integrity_status',
            'movementsAtCutoff' => 'movements_at_cutoff',
            'lastMovementAt' => 'last_movement_at',
            'ledgerAtCutoff' => 'ledger_at_cutoff',
            'balanceAfterAtCutoff' => 'balance_after_at_cutoff',
            'cutoffDifference' => 'ABS(cutoff_difference)',
            'debtAtCutoff' => 'debt_at_cutoff',
            'debtAfterAtCutoff' => 'debt_after_at_cutoff',
            'debtCutoffDifference' => 'ABS(debt_cutoff_difference)',
            'currentBalance' => 'current_balance',
            'currentLedger' => 'current_ledger',
            'currentDifference' => 'ABS(current_difference)',
            'currentDebt' => 'current_debt',
            'currentDebtLedger' => 'current_debt_ledger',
            'currentDebtDifference' => 'ABS(current_debt_difference)',
        ];
        $order = $sortMap[$sort] ?? 'ABS(cutoff_difference)';
        $sqlDirection = $direction === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $sort === '__exportKey'
            ? 'account_id ASC'
            : "{$order} {$sqlDirection} NULLS LAST, account_id ASC";
        $rows = $this->fetchAll(
            "SELECT * FROM ({$base}) reconciliation
             {$where}
             ORDER BY {$orderSql}
             {$this->paginationClause($limit, $offset)}",
            $params
        );

        return [
            'rows' => array_map(static fn(array $row): array => [
                'accountId' => (string)$row['account_id'],
                'accountName' => (string)$row['account_name'],
                'integrityStatus' => (string)$row['integrity_status'],
                'movementsAtCutoff' => (int)$row['movements_at_cutoff'],
                'lastMovementAt' => $row['last_movement_at'] === null ? null : (string)$row['last_movement_at'],
                'ledgerAtCutoff' => (int)$row['ledger_at_cutoff'],
                'balanceAfterAtCutoff' => (int)$row['balance_after_at_cutoff'],
                'cutoffDifference' => (int)$row['cutoff_difference'],
                'debtAtCutoff' => (int)$row['debt_at_cutoff'],
                'debtAfterAtCutoff' => (int)$row['debt_after_at_cutoff'],
                'debtCutoffDifference' => (int)$row['debt_cutoff_difference'],
                'currentBalance' => (int)$row['current_balance'],
                'currentLedger' => (int)$row['current_ledger'],
                'currentDifference' => (int)$row['current_difference'],
                'currentDebt' => (int)$row['current_debt'],
                'currentDebtLedger' => (int)$row['current_debt_ledger'],
                'currentDebtDifference' => (int)$row['current_debt_difference'],
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function reconciliationBase(array $context): array
    {
        $sql = "WITH candidate_members AS (
                    SELECT account.member_id
                    FROM loyalty_point_accounts account
                    LEFT JOIN loyalty_members account_member
                      ON account_member.tenant_id = account.tenant_id
                     AND account_member.id = account.member_id
                    WHERE account.tenant_id = :tenant_id
                      AND (account_member.id IS NULL OR account_member.created_at < :to_exclusive)
                    UNION
                    SELECT member_id
                    FROM loyalty_point_ledger
                    WHERE tenant_id = :tenant_id AND created_at < :to_exclusive
                    UNION
                    SELECT member_id
                    FROM loyalty_debt_ledger
                    WHERE tenant_id = :tenant_id AND created_at < :to_exclusive
                    UNION
                    SELECT id AS member_id
                    FROM loyalty_members
                    WHERE tenant_id = :tenant_id AND created_at < :to_exclusive
                )
                SELECT COALESCE(m.account_id, '[orphan:' || candidate.member_id || ']') AS account_id,
                       COALESCE(m.account_name, '[Socio ausente]') AS account_name,
                       (m.id IS NULL) AS member_missing,
                       (a.id IS NULL) AS account_missing,
                       CASE
                           WHEN m.id IS NULL AND a.id IS NULL THEN 'missing_member_and_account'
                           WHEN m.id IS NULL THEN 'missing_member'
                           WHEN a.id IS NULL THEN 'missing_account'
                           ELSE 'complete'
                       END AS integrity_status,
                       COALESCE(cutoff.movements, 0) AS movements_at_cutoff,
                       CASE WHEN cutoff.last_movement_at IS NULL THEN NULL
                            ELSE to_char(cutoff.last_movement_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') END AS last_movement_at,
                       COALESCE(cutoff.ledger_balance, 0) AS ledger_at_cutoff,
                       COALESCE(last_entry.balance_after, 0) AS balance_after_at_cutoff,
                       COALESCE(cutoff.ledger_balance, 0) - COALESCE(last_entry.balance_after, 0) AS cutoff_difference,
                       COALESCE(a.balance, 0) AS current_balance,
                       COALESCE(current_state.ledger_balance, 0) AS current_ledger,
                       COALESCE(a.balance, 0) - COALESCE(current_state.ledger_balance, 0) AS current_difference,
                       COALESCE(debt_cutoff.debt_balance, 0) AS debt_at_cutoff,
                       COALESCE(last_debt.debt_after, 0) AS debt_after_at_cutoff,
                       COALESCE(debt_cutoff.debt_balance, 0) - COALESCE(last_debt.debt_after, 0) AS debt_cutoff_difference,
                       COALESCE(a.points_debt, 0) AS current_debt,
                       COALESCE(current_debt.debt_balance, 0) AS current_debt_ledger,
                       COALESCE(a.points_debt, 0) - COALESCE(current_debt.debt_balance, 0) AS current_debt_difference
                FROM candidate_members candidate
                LEFT JOIN loyalty_members m
                  ON m.tenant_id = :tenant_id AND m.id = candidate.member_id
                LEFT JOIN loyalty_point_accounts a
                  ON a.tenant_id = :tenant_id AND a.member_id = candidate.member_id
                LEFT JOIN LATERAL (
                    SELECT COUNT(*) AS movements, MAX(l.created_at) AS last_movement_at,
                           COALESCE(SUM(l.points), 0) AS ledger_balance
                    FROM loyalty_point_ledger l
                    WHERE l.tenant_id = :tenant_id AND l.member_id = candidate.member_id
                      AND l.created_at < :to_exclusive
                ) cutoff ON TRUE
                LEFT JOIN LATERAL (
                    SELECT l.balance_after
                    FROM loyalty_point_ledger l
                    WHERE l.tenant_id = :tenant_id AND l.member_id = candidate.member_id
                      AND l.created_at < :to_exclusive
                    ORDER BY l.sequence_no DESC
                    LIMIT 1
                ) last_entry ON TRUE
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(l.points), 0) AS ledger_balance
                    FROM loyalty_point_ledger l
                    WHERE l.tenant_id = :tenant_id AND l.member_id = candidate.member_id
                ) current_state ON TRUE
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(d.points), 0) AS debt_balance
                    FROM loyalty_debt_ledger d
                    WHERE d.tenant_id = :tenant_id AND d.member_id = candidate.member_id
                      AND d.created_at < :to_exclusive
                ) debt_cutoff ON TRUE
                LEFT JOIN LATERAL (
                    SELECT d.debt_after
                    FROM loyalty_debt_ledger d
                    WHERE d.tenant_id = :tenant_id AND d.member_id = candidate.member_id
                      AND d.created_at < :to_exclusive
                    ORDER BY d.sequence_no DESC
                    LIMIT 1
                ) last_debt ON TRUE
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(d.points), 0) AS debt_balance
                    FROM loyalty_debt_ledger d
                    WHERE d.tenant_id = :tenant_id AND d.member_id = candidate.member_id
                ) current_debt ON TRUE";

        return [$sql, ['tenant_id' => $context['tenantId'], 'to_exclusive' => $context['toExclusive']]];
    }

    /** @return array<int,array{bucket:string,total:int}> */
    private function timeCountRows(string $table, string $column, array $context): array
    {
        $allowed = [
            'loyalty_members.created_at',
            'loyalty_wallet_passes.created_at',
        ];
        if (!in_array($table . '.' . $column, $allowed, true)) {
            throw new \LogicException('Serie temporal no permitida.');
        }
        $grain = $context['grain'] === 'hour' ? 'hour' : 'day';
        $format = $grain === 'hour' ? 'YYYY-MM-DD HH24:00' : 'YYYY-MM-DD';
        $raw = $this->fetchAll(
            "SELECT to_char(date_trunc('{$grain}', {$column}), '{$format}') AS bucket, COUNT(*) AS total
             FROM {$table}
             WHERE tenant_id = :tenant_id
               AND {$column} >= :from_start
               AND {$column} < :to_exclusive
             GROUP BY 1 ORDER BY 1",
            $this->rangeParams($context)
        );
        $indexed = [];
        foreach ($raw as $row) {
            $indexed[(string)$row['bucket']] = (int)$row['total'];
        }
        $result = [];
        foreach ($this->bucketLabels($context) as $bucket) {
            $result[] = ['bucket' => $bucket, 'total' => $indexed[$bucket] ?? 0];
        }

        return $result;
    }

    /** @return array<int,string> */
    private function bucketLabels(array $context, bool $forceDay = false): array
    {
        $zone = new DateTimeZone($context['timezone']);
        $cursor = new DateTimeImmutable($context['fromStart'], $zone);
        $end = new DateTimeImmutable($context['toExclusive'], $zone);
        $hourly = !$forceDay && $context['grain'] === 'hour';
        $interval = $hourly ? new DateInterval('PT1H') : new DateInterval('P1D');
        $format = $hourly ? 'Y-m-d H:00' : 'Y-m-d';
        $labels = [];
        while ($cursor < $end) {
            $labels[] = $cursor->format($format);
            $cursor = $cursor->add($interval);
        }

        return $labels;
    }

    /** @return array<string,mixed> */
    private function categoricalTimeChart(
        string $key,
        string $title,
        array $context,
        array $rows,
        string $categoryKey,
        string $valueKey,
        string $unit,
        string $summary
    ): array {
        $buckets = $this->bucketLabels($context);
        $categories = [];
        foreach ($rows as $row) {
            $category = (string)($row[$categoryKey] ?? 'sin-clasificar');
            $categories[$category] = true;
        }
        ksort($categories);
        $matrix = [];
        foreach (array_keys($categories) as $category) {
            $matrix[$category] = array_fill_keys($buckets, 0);
        }
        foreach ($rows as $row) {
            $category = (string)($row[$categoryKey] ?? 'sin-clasificar');
            $bucket = (string)($row['bucket'] ?? '');
            if (isset($matrix[$category][$bucket])) {
                $matrix[$category][$bucket] = (int)($row[$valueKey] ?? 0);
            }
        }
        $series = [];
        foreach ($matrix as $category => $values) {
            $series[] = $this->series($category, $category, array_values($values));
        }

        return [
            'key' => $key,
            'title' => $title,
            'type' => 'stacked-bar',
            'granularity' => $context['grain'],
            'categories' => $buckets,
            'series' => $series,
            'unit' => $unit,
            'summary' => $summary,
        ];
    }

    /** @return array<string,mixed> */
    private function metric(string $key, string $label, int|float $value, string $format, string $definition): array
    {
        return compact('key', 'label', 'value', 'format', 'definition');
    }

    /** @return array{key:string,label:string,data:array<int,int|float>} */
    private function series(string $key, string $label, array $data): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'data' => array_map(static fn($value) => is_float($value) ? $value : (int)$value, array_values($data)),
        ];
    }

    /** @return array{tenant_id:string,from_start:string,to_exclusive:string} */
    private function rangeParams(array $context): array
    {
        return [
            'tenant_id' => $context['tenantId'],
            'from_start' => $context['fromStart'],
            'to_exclusive' => $context['toExclusive'],
        ];
    }

    private function iterateAllRows(
        string $reportKey,
        array $context,
        array $filters,
        callable $consumer,
        ?int $knownTotal = null
    ): void {
        $previousKnownTotal = $this->knownExportRowCount;
        $this->knownExportRowCount = $knownTotal;
        try {
            $exported = 0;
            $cursor = null;
            do {
                $batchFilters = $filters;
                if ($cursor !== null) {
                    $batchFilters['__exportCursor'] = $cursor;
                }
                $page = $this->queryTable(
                    $reportKey,
                    $context,
                    $batchFilters,
                    self::EXPORT_BATCH_SIZE,
                    0,
                    '__exportKey',
                    'asc'
                );
                $rows = $page['rows'];
                if ($rows !== []) {
                    $consumer($rows);
                    $exported += count($rows);
                    $nextCursor = $this->exportCursorForRow($reportKey, $rows[array_key_last($rows)]);
                    if ($cursor !== null && $nextCursor === $cursor) {
                        throw new \RuntimeException('El cursor de exportacion no avanzo; se cancela para evitar duplicados.');
                    }
                    $cursor = $nextCursor;
                }
            } while ($rows !== [] && count($rows) === self::EXPORT_BATCH_SIZE && $exported < $page['total']);
            if ($knownTotal !== null && $exported !== $knownTotal) {
                throw new \RuntimeException(sprintf(
                    'La exportacion esperaba %d filas y obtuvo %d; no se generara un archivo incompleto.',
                    $knownTotal,
                    $exported
                ));
            }
        } finally {
            $this->knownExportRowCount = $previousKnownTotal;
        }
    }

    /** @return array<string,string> */
    private function exportCursorForRow(string $reportKey, array $row): array
    {
        return match ($reportKey) {
            'executive-summary' => ['bucket' => (string)$row['bucket']],
            'point-activity' => [
                'ledgerKind' => (string)$row['ledgerKind'],
                'id' => (string)$row['id'],
            ],
            'members-tiers' => [
                'tier' => (string)$row['tier'],
                'status' => (string)$row['status'],
                'walletPlatform' => (string)$row['walletPlatform'],
            ],
            'card-adoption' => [
                'platform' => (string)$row['platform'],
                'status' => (string)$row['status'],
            ],
            'redemptions-rewards', 'risk-events', 'audit-events' => ['id' => (string)$row['id']],
            'api-usage' => [
                'date' => (string)$row['date'],
                'clientId' => (string)$row['clientId'],
            ],
            'ledger-reconciliation' => ['accountId' => (string)$row['accountId']],
            default => throw new \InvalidArgumentException('Reporte no disponible para exportacion.'),
        };
    }

    private function paginationClause(int $limit, int $offset): string
    {
        return 'LIMIT ' . max(1, $limit) . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    private function assertExportSize(int $rowCount): void
    {
        if ($rowCount > self::MAX_EXPORT_ROWS) {
            throw new LoyaltyReportExportTooLargeException($rowCount, self::MAX_EXPORT_ROWS);
        }
    }

    private function writeDataWorksheet(
        string $reportKey,
        array $definition,
        array $context,
        array $filters,
        int $rowCount
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'loyalty-report-sheet-');
        if (!is_string($path)) {
            throw new \RuntimeException('No se pudo preparar la hoja de datos.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw new \RuntimeException('No se pudo abrir la hoja de datos.');
        }
        $completed = false;
        try {
            fwrite($handle, $this->worksheetStart());
            $rowIndex = 1;
            $headers = array_map(
                static fn(array $column): array => ['value' => $column['label'], 'style' => 2],
                $definition['columns']
            );
            fwrite($handle, $this->xlsxRow($rowIndex++, $headers));
            $this->iterateAllRows(
                $reportKey,
                $context,
                $filters,
                function (array $rows) use ($handle, $definition, &$rowIndex): void {
                    foreach ($rows as $row) {
                        $cells = [];
                        foreach ($definition['columns'] as $column) {
                            $cells[] = [
                                'value' => $row[$column['key']] ?? null,
                                'type' => $column['type'],
                            ];
                        }
                        fwrite($handle, $this->xlsxRow($rowIndex++, $cells));
                    }
                },
                $rowCount
            );
            $lastColumn = $this->xlsxColumnName(max(0, count($definition['columns']) - 1));
            fwrite($handle, $this->worksheetEnd('A1:' . $lastColumn . max(1, $rowIndex - 1)));
            fclose($handle);
            $completed = true;

            return $path;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$completed) {
                @unlink($path);
            }
        }
    }

    /** @param array<int,array<int,array<string,mixed>>> $rows */
    private function writeWorksheet(array $rows, int $freezeRow): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loyalty-report-sheet-');
        if (!is_string($path)) {
            throw new \RuntimeException('No se pudo preparar una hoja Excel.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw new \RuntimeException('No se pudo abrir una hoja Excel.');
        }
        fwrite($handle, $this->worksheetStart($freezeRow));
        $rowIndex = 1;
        $maximumColumns = 1;
        foreach ($rows as $row) {
            $maximumColumns = max($maximumColumns, count($row));
            fwrite($handle, $this->xlsxRow($rowIndex++, $row));
        }
        $lastColumn = $this->xlsxColumnName($maximumColumns - 1);
        fwrite($handle, $this->worksheetEnd('A1:' . $lastColumn . max(1, $rowIndex - 1)));
        fclose($handle);

        return $path;
    }

    private function worksheetStart(int $freezeRow = 1): string
    {
        $pane = $freezeRow > 0
            ? '<pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols><col min="1" max="4" width="24" customWidth="1"/>'
            . '<col min="5" max="40" width="20" customWidth="1"/></cols><sheetData>';
    }

    private function worksheetEnd(string $autoFilter): string
    {
        return '</sheetData><autoFilter ref="' . $this->xml($autoFilter) . '"/></worksheet>';
    }

    /** @param array<int,array<string,mixed>|scalar|null> $cells */
    private function xlsxRow(int $rowIndex, array $cells): string
    {
        $xml = '<row r="' . $rowIndex . '">';
        foreach (array_values($cells) as $columnIndex => $cell) {
            $value = is_array($cell) ? ($cell['value'] ?? null) : $cell;
            $style = is_array($cell) ? (int)($cell['style'] ?? 0) : 0;
            $type = is_array($cell) ? (string)($cell['type'] ?? 'text') : 'text';
            $xml .= $this->xlsxCell($rowIndex, $columnIndex, $value, $type, $style);
        }

        return $xml . '</row>';
    }

    private function xlsxCell(int $rowIndex, int $columnIndex, mixed $value, string $type, int $style): string
    {
        $reference = $this->xlsxColumnName($columnIndex) . $rowIndex;
        if ($value === null || $value === '') {
            return '<c r="' . $reference . '"/>';
        }
        if (in_array($type, ['integer', 'points', 'percent'], true) && is_numeric($value)) {
            return '<c r="' . $reference . '" s="' . $style . '"><v>' . $this->xml((string)$value) . '</v></c>';
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            try {
                $date = new DateTimeImmutable((string)$value, new DateTimeZone(self::DEFAULT_TIMEZONE));
                $excelEpoch = new DateTimeImmutable('1899-12-30 00:00:00', $date->getTimezone());
                $serial = ((float)($date->getTimestamp() - $excelEpoch->getTimestamp())) / 86400;
                $dateStyle = $type === 'date' ? 3 : 4;
                return '<c r="' . $reference . '" s="' . $dateStyle . '"><v>' . $serial . '</v></c>';
            } catch (\Throwable) {
                // Un dato temporal inesperado se conserva como texto, nunca como formula.
            }
        }
        $text = $this->spreadsheetText($value);

        return '<c r="' . $reference . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
            . $this->xml($text) . '</t></is></c>';
    }

    private function xlsxColumnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** @param array<int,array{name:string,path:string}> $sheets */
    private function buildWorkbookFile(string $title, array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loyalty-report-xlsx-');
        if (!is_string($path)) {
            throw new \RuntimeException('No se pudo preparar el archivo Excel.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new \RuntimeException('No se pudo crear el archivo Excel.');
        }

        $overrides = '';
        $workbookSheets = '';
        $relationships = '';
        foreach ($sheets as $index => $sheet) {
            $sheetId = $index + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetId
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . $sheetId
                . '" r:id="rId' . $sheetId . '"/>';
            $relationships .= '<Relationship Id="rId' . $sheetId
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $sheetId . '.xml"/>';
            if (!$zip->addFile($sheet['path'], 'xl/worksheets/sheet' . $sheetId . '.xml')) {
                $zip->close();
                @unlink($path);
                throw new \RuntimeException('No se pudo agregar una hoja al archivo Excel.');
            }
        }
        $styleRelationshipId = count($sheets) + 1;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>Fidepuntos</Application></Properties>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->xml($title) . '</dc:title><dc:creator>Fidepuntos</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relationships
            . '<Relationship Id="rId' . $styleRelationshipId
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->close();

        return $path;
    }

    private function xlsxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/>'
            . '<numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm:ss"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><color rgb="FF0F172A"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Arial"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF4F1"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD9E7E2"/></left>'
            . '<right style="thin"><color rgb="FFD9E7E2"/></right>'
            . '<top style="thin"><color rgb="FFD9E7E2"/></top>'
            . '<bottom style="thin"><color rgb="FFD9E7E2"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param resource $handle */
    private function csvLine($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '');
    }

    private function spreadsheetText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        $text = (string)$value;
        if (mb_strlen($text, 'UTF-8') > self::MAX_SPREADSHEET_CELL_CHARS) {
            $suffix = '… [TRUNCATED]';
            $text = mb_substr(
                $text,
                0,
                self::MAX_SPREADSHEET_CELL_CHARS - mb_strlen($suffix, 'UTF-8'),
                'UTF-8'
            ) . $suffix;
        }
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/u', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }

    private function safeJson(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return mb_substr($value, 0, 4000);
            }
            $value = $decoded;
        } elseif (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return (string)$value;
        }
        $redacted = $this->redactSensitive($value);

        return json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function redactSensitive(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string)$key);
            $compactKey = preg_replace('/[^a-z0-9]+/', '', $normalizedKey) ?? $normalizedKey;
            if (preg_match(
                '/(password|secret|token|apikey|otp|signature|authorization|cookie|session|bearer|credential|privatekey|codehash)/',
                $compactKey
            ) === 1 || in_array($compactKey, ['code', 'pin'], true)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $result[$key] = $this->redactSensitive($item);
            } elseif (is_object($item)) {
                $result[$key] = $this->redactSensitive((array)$item);
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function filename(string $reportKey, string $extension): string
    {
        $safe = preg_replace('/[^a-z0-9-]+/i', '-', $reportKey) ?: 'reporte';

        return 'fidepuntos-' . $safe . '-' . date('Ymd-His') . '.' . $extension;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function tenantId(): string
    {
        $tenantId = TenantContext::id() ?: TenantContext::slug();
        if (!is_string($tenantId) || trim($tenantId) === '') {
            throw new \RuntimeException('Tenant Loyalty no resuelto.');
        }

        return trim($tenantId);
    }
}
