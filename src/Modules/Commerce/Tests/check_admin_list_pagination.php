<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Http\Shared\ManagedUserControllerBase;
use App\Modules\Commerce\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Support\ModularControllerBoundary;

/** @return list<string> */
$calls = static function (string $class, string $method): array {
    $reflection = new ReflectionMethod($class, $method);
    $lines = file($reflection->getFileName());
    $source = implode('', array_slice(
        is_array($lines) ? $lines : [],
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
    return ModularControllerBoundary::methodCallNames($source);
};

$source = static function (string $class, string $method): string {
    $reflection = new ReflectionMethod($class, $method);
    $lines = file($reflection->getFileName());
    return implode('', array_slice(
        is_array($lines) ? $lines : [],
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
};

$customerCalls = $calls(ManagedUserControllerBase::class, 'ecommerceUsers');
$orderIndexCalls = $calls(OrderController::class, 'respondWithOrderPage');
$orderUserPage = $source(OrderRepository::class, 'getByUserIdPage');
$orderAdminPage = $source(OrderRepository::class, 'getPageResult');
$orderSummaryPage = $source(OrderRepository::class, 'getOrderSummaryPage');
$customerPage = $source(UserRepository::class, 'getPage');

$checks = [
    'customers call bounded repository page' => in_array('getPage', $customerCalls, true),
    'customers emit an opaque next cursor' => in_array('encode', $customerCalls, true),
    'orders use both bounded page variants' => in_array('getPageResult', $orderIndexCalls, true)
        && in_array('getByUserIdPage', $orderIndexCalls, true),
    'orders emit an opaque next cursor' => in_array('encode', $orderIndexCalls, true),
    'personal orders delegate to bounded summary query' => str_contains($orderUserPage, 'getOrderSummaryPage'),
    'admin orders delegate to bounded summary query' => str_contains($orderAdminPage, 'getOrderSummaryPage'),
    'shared order summary SQL is keyset and bounded' => str_contains($orderSummaryPage, '(o.created_at, o.id) <')
        && str_contains($orderSummaryPage, '$safeLimit + 1'),
    'shared order summary avoids aggregate payload columns' => !preg_match('/SELECT\s+o\.\*/i', $orderSummaryPage)
        && !preg_match('/SELECT\s+oi\.\*/i', $orderSummaryPage)
        && !preg_match('/\b(order_notes|shipping_address|billing_address|payment_details|invoice_data)\b/i', $orderSummaryPage),
    'customer SQL supports keyset' => str_contains($customerPage, '(u.created_at, u.id) <'),
    'legacy customer offset is capped at 4900' => str_contains($customerPage, "'max_range' => 4900"),
    'customer search and role remain before ordering' => strpos($customerPage, '$roleFilterSql . $cursorFilterSql') !== false,
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Admin list pagination contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Admin list pagination contract: OK\n";
