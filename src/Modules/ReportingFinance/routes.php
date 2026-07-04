<?php

return [
    ['method' => 'GET', 'path' => '/api/admin/dashboard/stats', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\DashboardController@stats', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/reports/general', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\GeneralReportController@show', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/reports/product-ranking', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@productRanking', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/reports/operational-alerts', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@operationalAlerts', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/reports/financial-overview', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\DashboardProjectionController@financialOverview', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/inventory/intelligence', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\InventoryIntelligenceController@intelligence', 'capability' => 'admin.reporting'],
    ['method' => 'GET', 'path' => '/api/admin/expenses', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@index', 'capability' => 'admin.finance'],
    ['method' => 'POST', 'path' => '/api/admin/expenses', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@store', 'capability' => 'admin.finance'],
    ['method' => 'GET', 'path' => '/api/admin/financial-periods', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@index', 'capability' => 'admin.finance'],
    ['method' => 'GET', 'path' => '/api/admin/financial-periods/{period}/preview', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@preview', 'capability' => 'admin.finance'],
    ['method' => 'POST', 'path' => '/api/admin/financial-periods/{period}/close', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@close', 'capability' => 'admin.finance'],
    ['method' => 'POST', 'path' => '/api/admin/financial-adjustments', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\FinancialPeriodController@storeAdjustment', 'capability' => 'admin.finance'],
    ['method' => 'GET', 'path' => '/api/admin/expenses/recurrences', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@recurrences', 'capability' => 'admin.finance'],
    ['method' => 'POST', 'path' => '/api/admin/expenses/recurrences', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@storeRecurrence', 'capability' => 'admin.finance'],
    ['method' => 'PUT', 'path' => '/api/admin/expenses/recurrences/{id}', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@updateRecurrence', 'capability' => 'admin.finance'],
    ['method' => 'DELETE', 'path' => '/api/admin/expenses/recurrences/{id}', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@deleteRecurrence', 'capability' => 'admin.finance'],
    ['method' => 'PUT', 'path' => '/api/admin/expenses/{id}', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@update', 'capability' => 'admin.finance'],
    ['method' => 'PATCH', 'path' => '/api/admin/expenses/{id}/status', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\BusinessExpenseController@updateStatus', 'capability' => 'admin.finance'],
    ['method' => 'GET', 'path' => '/api/reports/recent-orders', 'handler' => 'App\\Modules\\ReportingFinance\\Controllers\\ReportController@recentOrders', 'capability' => 'admin.reporting'],
];
