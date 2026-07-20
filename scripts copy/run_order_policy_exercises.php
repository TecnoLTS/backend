<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Commerce\Controllers\OrderController;
use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Repositories\CustomerRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$tenantId = 'paramascotasec';
TenantContext::set([
    'id' => $tenantId,
    'name' => 'Para Mascotas EC',
]);

/** @var PDO $db */
$db = Database::getModuleInstance(CommerceDomain::KEY);
$customerRepository = new CustomerRepository();
$controller = new OrderController();

$runToken = gmdate('YmdHis');
$createdUserIds = [];
$createdEmails = [];

$report = [
    'tenant_id' => $tenantId,
    'started_at' => date('c'),
    'checks' => [],
];

$invokePrivate = static function (object $object, string $method, mixed ...$args): mixed {
    $caller = \Closure::bind(
        function (string $method, array $args): mixed {
            return $this->{$method}(...$args);
        },
        $object,
        get_class($object)
    );

    return $caller($method, $args);
};

$createQaCustomer = static function (string $suffix) use ($customerRepository, &$createdUserIds, &$createdEmails): array {
    $email = sprintf('qa.order-policy.%s.%s@paramascotasec.com', gmdate('YmdHis'), $suffix);
    $created = $customerRepository->create([
        'name' => 'QA Order Policy ' . strtoupper($suffix),
        'email' => $email,
        'password' => bin2hex(random_bytes(12)),
        'role' => 'customer',
        'document_type' => 'cedula',
        'document_number' => '1702527887',
    ], [
        'skip_verification_token' => true,
        'email_verified' => true,
    ]);

    $user = $customerRepository->getById((string)$created['id']);
    if (!is_array($user)) {
        throw new RuntimeException('No se pudo recuperar el usuario QA creado.');
    }

    $createdUserIds[] = (string)$user['id'];
    $createdEmails[] = $email;

    return $user;
};

$fetchCustomerState = static function (string $userId) use ($db, $tenantId): array {
    $stmt = $db->prepare('
        SELECT id, email, failed_login_attempts, login_locked_until, active_token_id
        FROM "Customer"
        WHERE id = :id AND tenant_id = :tenant_id
        LIMIT 1
    ');
    $stmt->execute([
        'id' => $userId,
        'tenant_id' => $tenantId,
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$fetchLatestSecurityEvent = static function (string $userId) use ($db, $tenantId): array {
    $stmt = $db->prepare('
        SELECT event_type, status, metadata
        FROM "CustomerAuthSecurityEvent"
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 1
    ');
    $stmt->execute([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (isset($row['metadata']) && is_string($row['metadata'])) {
        $decoded = json_decode($row['metadata'], true);
        $row['metadata'] = is_array($decoded) ? $decoded : [];
    }

    return $row;
};

try {
    $moneyTamperUser = $createQaCustomer('money');
    $_SERVER['REQUEST_URI'] = '/api/orders';
    $_SERVER['HTTP_USER_AGENT'] = 'qa-order-policy-script';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    http_response_code(200);
    ob_start();
    $moneyTamperTriggered = $invokePrivate(
        $controller,
        'rejectUnexpectedMoneyFields',
        [
            'items' => [
                ['product_id' => 'demo', 'quantity' => 1],
            ],
            'total' => 999.99,
            'shipping' => 4.50,
        ],
        [
            'sub' => (string)$moneyTamperUser['id'],
            'email' => (string)$moneyTamperUser['email'],
            'role' => 'customer',
        ]
    );
    $moneyTamperOutput = ob_get_clean();
    $moneyTamperPayload = json_decode((string)$moneyTamperOutput, true);
    $moneyTamperState = $fetchCustomerState((string)$moneyTamperUser['id']);
    $moneyTamperEvent = $fetchLatestSecurityEvent((string)$moneyTamperUser['id']);

    $report['checks']['money_tamper'] = [
        'triggered' => $moneyTamperTriggered === true,
        'http_status' => http_response_code(),
        'response_code' => $moneyTamperPayload['error']['code'] ?? null,
        'blocked' => ((int)($moneyTamperState['failed_login_attempts'] ?? 0)) >= 999
            && trim((string)($moneyTamperState['login_locked_until'] ?? '')) !== '',
        'event_type' => $moneyTamperEvent['event_type'] ?? null,
        'event_status' => $moneyTamperEvent['status'] ?? null,
        'event_fields' => $moneyTamperEvent['metadata']['fields'] ?? [],
    ];

    $itemTamperUser = $createQaCustomer('item');
    http_response_code(200);
    ob_start();
    $itemTamperTriggered = $invokePrivate(
        $controller,
        'rejectUnexpectedItemPricingFields',
        [
            'items' => [
                [
                    'product_id' => 'demo',
                    'quantity' => 1,
                    'price' => 12.00,
                    'tax_amount' => 1.50,
                ],
            ],
        ],
        [
            'sub' => (string)$itemTamperUser['id'],
            'email' => (string)$itemTamperUser['email'],
            'role' => 'customer',
        ]
    );
    $itemTamperOutput = ob_get_clean();
    $itemTamperPayload = json_decode((string)$itemTamperOutput, true);
    $itemTamperState = $fetchCustomerState((string)$itemTamperUser['id']);
    $itemTamperEvent = $fetchLatestSecurityEvent((string)$itemTamperUser['id']);

    $report['checks']['item_tamper'] = [
        'triggered' => $itemTamperTriggered === true,
        'http_status' => http_response_code(),
        'response_code' => $itemTamperPayload['error']['code'] ?? null,
        'blocked' => ((int)($itemTamperState['failed_login_attempts'] ?? 0)) >= 999
            && trim((string)($itemTamperState['login_locked_until'] ?? '')) !== '',
        'event_type' => $itemTamperEvent['event_type'] ?? null,
        'event_status' => $itemTamperEvent['status'] ?? null,
        'event_fields' => $itemTamperEvent['metadata']['fields'] ?? [],
    ];

    $finalConsumerLimitMessage = null;
    try {
        $invokePrivate(
            $controller,
            'assertFinalConsumerAllowedForOrderData',
            [
                'billing_address' => [
                    'firstName' => 'Consumidor',
                    'lastName' => 'Final',
                    'documentType' => 'consumidor_final',
                    'documentNumber' => '9999999999999',
                ],
            ],
            50.01
        );
        $report['checks']['final_consumer_over_limit'] = [
            'blocked' => false,
            'message' => null,
        ];
    } catch (\DomainException $exception) {
        $finalConsumerLimitMessage = $exception->getMessage();
        $report['checks']['final_consumer_over_limit'] = [
            'blocked' => true,
            'message' => $finalConsumerLimitMessage,
        ];
    }

    $validHighValueCustomerAllowed = false;
    try {
        $invokePrivate(
            $controller,
            'assertFinalConsumerAllowedForOrderData',
            [
                'billing_address' => [
                    'firstName' => 'Cliente',
                    'lastName' => 'Valido',
                    'documentType' => 'cedula',
                    'documentNumber' => '1702527887',
                ],
            ],
            120.75
        );
        $validHighValueCustomerAllowed = true;
    } catch (\Throwable) {
        $validHighValueCustomerAllowed = false;
    }

    $report['checks']['identified_customer_over_limit'] = [
        'allowed' => $validHighValueCustomerAllowed,
    ];
} finally {
    if (!empty($createdUserIds)) {
        $cleanupEvents = $db->prepare('DELETE FROM "CustomerAuthSecurityEvent" WHERE tenant_id = :tenant_id AND user_id = :user_id');
        foreach ($createdUserIds as $userId) {
            $cleanupEvents->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            $customerRepository->deleteById($userId);
        }
    }
}

$report['finished_at'] = date('c');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
