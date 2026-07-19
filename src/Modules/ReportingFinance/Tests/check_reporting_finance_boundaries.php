<?php

declare(strict_types=1);

use App\Modules\ReportingFinance\Application\Ports\BusinessExpensePort;
use App\Modules\ReportingFinance\Application\Ports\DashboardAnalyticsPort;
use App\Modules\ReportingFinance\Application\Ports\FinancialPeriodPort;
use App\Modules\ReportingFinance\Application\Ports\InventoryAnalyticsPort;
use App\Modules\ReportingFinance\Application\Ports\SalesReportingPort;
use App\Modules\ReportingFinance\Controllers\BusinessExpenseController;
use App\Modules\ReportingFinance\Controllers\DashboardController;
use App\Modules\ReportingFinance\Controllers\DashboardProjectionController;
use App\Modules\ReportingFinance\Controllers\FinancialPeriodController;
use App\Modules\ReportingFinance\Controllers\GeneralReportController;
use App\Modules\ReportingFinance\Controllers\InventoryIntelligenceController;
use App\Modules\ReportingFinance\Controllers\ReportController;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessBusinessExpenseAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessCatalogInventoryAnalyticsAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessCommerceSalesReportingAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessDashboardAnalyticsAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessFinancialPeriodAdapter;
use App\Repositories\OrderRepository;
use App\Services\BusinessIntelligenceService;
use App\Services\InventoryIntelligenceService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$moduleRoot = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (glob($moduleRoot . '/Controllers/*.php') ?: [] as $controllerFile) {
    $source = file_get_contents($controllerFile);
    $label = basename($controllerFile);
    $assert(is_string($source), $label . ': source unreadable');
    if (!is_string($source)) {
        continue;
    }

    $assert(!str_contains($source, 'App\\Repositories\\'), $label . ': imports a flat repository');
    $assert(!str_contains($source, 'App\\Services\\'), $label . ': imports a legacy service');
    $assert(!str_contains($source, 'Infrastructure\\Adapters\\'), $label . ': imports a concrete adapter');
    $assert(!preg_match('/new\\s+(?:\\\\)?App\\\\(?:Repositories|Services)\\\\/', $source), $label . ': creates a cross-domain implementation');
}

foreach (glob($moduleRoot . '/Application/Ports/*.php') ?: [] as $portFile) {
    $source = file_get_contents($portFile);
    $label = basename($portFile);
    $assert(is_string($source), $label . ': source unreadable');
    if (!is_string($source)) {
        continue;
    }

    $assert(!str_contains($source, 'App\\Repositories\\'), $label . ': leaks a repository type');
    $assert(!str_contains($source, 'App\\Services\\'), $label . ': leaks a service type');
}

$assert(is_subclass_of(InProcessBusinessExpenseAdapter::class, BusinessExpensePort::class), 'Expense adapter does not implement BusinessExpensePort');
$assert(is_subclass_of(InProcessFinancialPeriodAdapter::class, FinancialPeriodPort::class), 'Period adapter does not implement FinancialPeriodPort');

$routes = require $moduleRoot . '/routes.php';
$routeSignatures = array_map(
    static fn (array $route): string => implode('|', [
        $route['method'] ?? '',
        $route['path'] ?? '',
        $route['handler'] ?? '',
        $route['capability'] ?? '',
    ]),
    $routes
);
$expectedRoutes = [
    'GET|/api/admin/dashboard/stats|App\\Modules\\ReportingFinance\\Controllers\\DashboardController@stats|admin.reporting',
    'GET|/api/admin/reports/general|App\\Modules\\ReportingFinance\\Controllers\\GeneralReportController@show|admin.reporting',
    'GET|/api/admin/reports/product-ranking|App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@productRanking|admin.reporting',
    'GET|/api/admin/reports/operational-alerts|App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@operationalAlerts|admin.reporting',
    'GET|/api/admin/reports/financial-overview|App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@financialOverview|admin.reporting',
    'GET|/api/admin/inventory/intelligence|App\\Modules\\ReportingFinance\\Controllers\\InventoryIntelligenceController@intelligence|admin.reporting',
    'GET|/api/admin/expenses|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@index|admin.finance',
    'POST|/api/admin/expenses|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@store|admin.finance',
    'GET|/api/admin/financial-periods|App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@index|admin.finance',
    'GET|/api/admin/financial-periods/{period}/preview|App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@preview|admin.finance',
    'POST|/api/admin/financial-periods/{period}/close|App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@close|admin.finance',
    'POST|/api/admin/financial-adjustments|App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@storeAdjustment|admin.finance',
    'GET|/api/admin/expenses/recurrences|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@recurrences|admin.finance',
    'POST|/api/admin/expenses/recurrences|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@storeRecurrence|admin.finance',
    'PUT|/api/admin/expenses/recurrences/{id}|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@updateRecurrence|admin.finance',
    'DELETE|/api/admin/expenses/recurrences/{id}|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@deleteRecurrence|admin.finance',
    'PUT|/api/admin/expenses/{id}|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@update|admin.finance',
    'PATCH|/api/admin/expenses/{id}/status|App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@updateStatus|admin.finance',
    'GET|/api/reports/recent-orders|App\\Modules\\ReportingFinance\\Controllers\\ReportController@recentOrders|admin.reporting',
];
$assert($routeSignatures === $expectedRoutes, 'HTTP route contract changed');

final class ReportingBoundaryOrderRepositoryStub extends OrderRepository
{
    public array $periodArgs = [];
    public int $recentLimit = 0;

    public function __construct()
    {
    }

    public function getReportPeriodSummary(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        ?string $selectedYear = null,
        array $options = []
    ): array {
        $this->periodArgs = [$selectedMonth, $selectedDate, $scope, $selectedYear, $options];
        return ['projection' => 'sales'];
    }

    public function getRecentOrders($limit = 5)
    {
        $this->recentLimit = (int)$limit;
        return [['id' => 'order-port']];
    }
}

final class ReportingBoundaryBusinessIntelligenceStub extends BusinessIntelligenceService
{
    public array $fullArgs = [];

    public function __construct()
    {
    }

    public function getFullDashboardStats(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        bool $includeReport = true
    ): array {
        $this->fullArgs = [$selectedMonth, $selectedDate, $scope, $includeReport];
        return ['projection' => 'dashboard'];
    }

    public function getProductRankingStats(?string $selectedMonth = null, ?string $selectedDate = null): array
    {
        return ['projection' => 'ranking', 'args' => [$selectedMonth, $selectedDate]];
    }

    public function getOperationalAlertStats(): array
    {
        return ['projection' => 'alerts'];
    }

    public function getFinancialOverviewStats(): array
    {
        return ['projection' => 'financial'];
    }
}

final class ReportingBoundaryInventoryIntelligenceStub extends InventoryIntelligenceService
{
    public array $args = [];

    public function __construct()
    {
    }

    public function getIntelligence(?int $windowDays = null, ?int $targetDays = null): array
    {
        $this->args = [$windowDays, $targetDays];
        return ['projection' => 'inventory'];
    }
}

$orderRepository = new ReportingBoundaryOrderRepositoryStub();
$salesAdapter = new InProcessCommerceSalesReportingAdapter($orderRepository);
$assert($salesAdapter instanceof SalesReportingPort, 'Commerce adapter does not implement SalesReportingPort');
$assert(
    $salesAdapter->periodSummary('2026-07', '2026-07-15', 'day', '2026', ['projection' => 'screen']) === ['projection' => 'sales'],
    'Commerce period projection changed'
);
$assert(
    $orderRepository->periodArgs === ['2026-07', '2026-07-15', 'day', '2026', ['projection' => 'screen']],
    'Commerce period arguments were not forwarded exactly'
);
$assert($salesAdapter->recentOrders(17) === [['id' => 'order-port']], 'Commerce recent orders projection changed');
$assert($orderRepository->recentLimit === 17, 'Commerce recent orders limit was not forwarded');

$businessIntelligence = new ReportingBoundaryBusinessIntelligenceStub();
$dashboardAdapter = new InProcessDashboardAnalyticsAdapter($businessIntelligence);
$assert($dashboardAdapter instanceof DashboardAnalyticsPort, 'Dashboard adapter does not implement DashboardAnalyticsPort');
$assert(
    $dashboardAdapter->fullDashboardStats('2026-07', '2026-07-15', 'week', false) === ['projection' => 'dashboard'],
    'Dashboard projection changed'
);
$assert(
    $businessIntelligence->fullArgs === ['2026-07', '2026-07-15', 'week', false],
    'Dashboard arguments were not forwarded exactly'
);
$assert($dashboardAdapter->productRankingStats('2026-07', null)['projection'] === 'ranking', 'Ranking projection changed');
$assert($dashboardAdapter->operationalAlertStats() === ['projection' => 'alerts'], 'Alerts projection changed');
$assert($dashboardAdapter->financialOverviewStats() === ['projection' => 'financial'], 'Financial projection changed');

$inventoryIntelligence = new ReportingBoundaryInventoryIntelligenceStub();
$inventoryAdapter = new InProcessCatalogInventoryAnalyticsAdapter($inventoryIntelligence);
$assert($inventoryAdapter instanceof InventoryAnalyticsPort, 'Inventory adapter does not implement InventoryAnalyticsPort');
$assert($inventoryAdapter->intelligence(45, 60) === ['projection' => 'inventory'], 'Inventory projection changed');
$assert($inventoryIntelligence->args === [45, 60], 'Inventory arguments were not forwarded exactly');

$salesPort = new class implements SalesReportingPort {
    public function periodSummary(?string $selectedMonth = null, ?string $selectedDate = null, ?string $scope = null, ?string $selectedYear = null, array $options = []): array { return []; }
    public function recentOrders(int $limit): array { return []; }
};
$analyticsPort = new class implements DashboardAnalyticsPort {
    public function fullDashboardStats(?string $selectedMonth = null, ?string $selectedDate = null, ?string $scope = null, bool $includeReport = true): array { return []; }
    public function productRankingStats(?string $selectedMonth = null, ?string $selectedDate = null): array { return []; }
    public function operationalAlertStats(): array { return []; }
    public function financialOverviewStats(): array { return []; }
};
$inventoryPort = new class implements InventoryAnalyticsPort {
    public function intelligence(?int $windowDays = null, ?int $targetDays = null): array { return []; }
};
$financialPeriodPort = new class implements FinancialPeriodPort {
    public function periodForDate(?string $date = null): array { return ['period_key' => '2026-07']; }
    public function getByPeriodKey(string $periodKey): ?array { return null; }
    public function listRecent(int $months = 14): array { return []; }
    public function listAdjustments(?string $periodKey = null, int $limit = 100): array { return []; }
    public function adjustmentSummary(?string $periodKey = null, bool $excludeClosedPeriods = false): array { return []; }
    public function createAdjustment(array $data, string $userId): array { return []; }
    public function closePeriod(string $periodKey, string $notes, string $userId): array { return []; }
    public function previewPeriod(string $periodKey): array { return []; }
};
$businessExpensePort = new class implements BusinessExpensePort {
    public function list(array $filters = []): array { return []; }
    public function categories(): array { return []; }
    public function create(array $data, string $userId): array { return []; }
    public function update(string $id, array $data): array { return []; }
    public function updateStatus(string $id, string $status, array $data = [], ?string $userId = null): array { return []; }
    public function summary(array $options = []): array { return []; }
    public function listRecurrences(): array { return []; }
    public function createRecurrence(array $data, string $userId): array { return []; }
    public function updateRecurrence(string $id, array $data): array { return []; }
    public function deleteRecurrence(string $id): array { return []; }
};

foreach ([
    new GeneralReportController($salesPort),
    new ReportController($salesPort),
    new DashboardController($salesPort, $analyticsPort),
    new DashboardProjectionController($analyticsPort),
    new InventoryIntelligenceController($inventoryPort),
    new FinancialPeriodController($financialPeriodPort),
    new BusinessExpenseController($businessExpensePort),
] as $controller) {
    $assert(str_starts_with($controller::class, 'App\\Modules\\ReportingFinance\\Controllers\\'), 'Controller injection escaped module boundary');
}

if ($failures !== []) {
    fwrite(STDERR, "ReportingFinance boundary failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "ReportingFinance ports/adapters boundary: OK\n";
