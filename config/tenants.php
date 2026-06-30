<?php

$env = static function (string $key, ?string $default = null): string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || trim((string)$value) === '') {
        return (string)($default ?? '');
    }
    return trim((string)$value);
};

$csv = static function (string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', $value)
    )));
};

$hostFromUrl = static function (string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
};

$tenantId = $env('DEFAULT_TENANT', $env('PUBLIC_TENANT_SLUG', 'paramascotasec'));
$tenantSlug = $env('PUBLIC_TENANT_SLUG', $tenantId);
$primaryDomain = strtolower($env('PRIMARY_SITE_DOMAIN', $hostFromUrl($env('APP_URL', 'https://localhost')) ?: 'localhost'));
$aliases = $csv($env('PRIMARY_SITE_ALIASES', "www.{$primaryDomain}"));
$appUrl = rtrim($env('APP_URL', "https://{$primaryDomain}"), '/');
$publicBaseUrl = rtrim($env('PUBLIC_BASE_URL', $appUrl), '/');
$scheme = parse_url($publicBaseUrl, PHP_URL_SCHEME) ?: $env('PUBLIC_SCHEME', 'https');
$dashboardEnabledModules = $csv($env('DASHBOARD_ENABLED_MODULES', 'dashboard,users,ecommerce'));
$dashboardPlatformAdminEmails = $csv($env('DASHBOARD_PLATFORM_ADMIN_EMAILS', ''));
$dashboardPlatformAdminDomains = $csv($env('DASHBOARD_PLATFORM_ADMIN_DOMAINS', 'tecnolts.com'));

$domains = array_values(array_unique(array_filter([$primaryDomain, ...$aliases])));
$allowedOrigins = array_values(array_unique(array_map(
    static fn (string $domain): string => "{$scheme}://{$domain}",
    $domains
)));

return [
    $tenantId => [
        'id' => $tenantId,
        'slug' => $tenantSlug,
        'name' => $env('TENANT_DISPLAY_NAME', 'Para Mascotas EC'),
        'db' => [
            'database' => $env('DB_DATABASE', 'ecommerce')
        ],
        'domains' => $domains,
        'allowed_origins' => $allowedOrigins,
        'app_url' => $appUrl,
        'public_base_url' => $publicBaseUrl,
        'enabled_modules' => $dashboardEnabledModules,
        'platform_admin_emails' => $dashboardPlatformAdminEmails,
        'platform_admin_domains' => $dashboardPlatformAdminDomains,
        'branding' => [
            'logo_url' => $env('DASHBOARD_BRANDING_LOGO_URL', 'assets/images/tenants/paramascotasec-logo.svg'),
            'logo_light_url' => $env('DASHBOARD_BRANDING_LOGO_LIGHT_URL', 'assets/images/tenants/paramascotasec-logo.svg'),
            'logo_icon_url' => $env('DASHBOARD_BRANDING_LOGO_ICON_URL', 'assets/images/tenants/paramascotasec-logo.png'),
            'primary_color' => $env('DASHBOARD_BRANDING_PRIMARY_COLOR', '#0a7b8f'),
        ],
    ]
];
