<?php

use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\Mailer\Domain\MailerDomain;
use App\Modules\ReportingFinance\Domain\ReportingFinanceDomain;

return [
    IdentityPlatformDomain::KEY => [
        'aliases' => [IdentityPlatformDomain::STORE_KEY, 'identityplatform'],
        'database' => IdentityPlatformDomain::STORE_KEY,
        'target_database' => IdentityPlatformDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
    CatalogInventoryDomain::KEY => [
        'aliases' => [CatalogInventoryDomain::STORE_KEY, 'cataloginventory'],
        'database' => CatalogInventoryDomain::STORE_KEY,
        'target_database' => CatalogInventoryDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
    CommerceDomain::KEY => [
        'aliases' => [CommerceDomain::STORE_KEY, 'commerce-orders'],
        'database' => CommerceDomain::STORE_KEY,
        'target_database' => CommerceDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
    BillingDomain::KEY => [
        'aliases' => [BillingDomain::STORE_KEY, 'billing-sri'],
        'database' => BillingDomain::STORE_KEY,
        'target_database' => BillingDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
    ReportingFinanceDomain::KEY => [
        'aliases' => [ReportingFinanceDomain::STORE_KEY, 'reportingfinance'],
        'database' => ReportingFinanceDomain::STORE_KEY,
        'target_database' => ReportingFinanceDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
    MailerDomain::KEY => [
        'aliases' => [MailerDomain::STORE_KEY, 'mailer', 'email-service'],
        'database' => MailerDomain::STORE_KEY,
        'target_database' => MailerDomain::STORE_KEY,
        'mode' => 'service-group',
    ],
];
