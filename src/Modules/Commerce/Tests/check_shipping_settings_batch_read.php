<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Core\TenantContext;
use App\Repositories\SettingsRepository;

final class ShippingSettingsBatchStatement
{
    /** @var list<array{key:string,value:string}> */
    private array $rows;

    /** @var array<string, mixed> */
    public array $params = [];

    /** @param list<array{key:string,value:string}> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @param array<string, mixed> $params */
    public function execute(array $params): bool
    {
        $this->params = $params;
        return true;
    }

    /** @return array{key:string,value:string}|false */
    public function fetch(): array|false
    {
        return array_shift($this->rows) ?? false;
    }
}

final class ShippingSettingsBatchConnection
{
    public int $prepareCalls = 0;
    public string $sql = '';
    public ShippingSettingsBatchStatement $statement;

    public function __construct()
    {
        $this->statement = new ShippingSettingsBatchStatement([
            ['key' => 'tenant-batch:shipping_delivery', 'value' => '5.00'],
            ['key' => 'tenant-batch:shipping_pickup', 'value' => '0.00'],
            // Una fila ajena nunca debe poder proyectarse sobre las claves
            // logicas solicitadas, incluso si un doble de DB la devuelve.
            ['key' => 'other-tenant:shipping_delivery', 'value' => '999.00'],
        ]);
    }

    public function prepare(string $sql): ShippingSettingsBatchStatement
    {
        $this->prepareCalls++;
        $this->sql = $sql;
        return $this->statement;
    }
}

$connection = new ShippingSettingsBatchConnection();
$repository = (new ReflectionClass(SettingsRepository::class))->newInstanceWithoutConstructor();
$databaseProperty = new ReflectionProperty(SettingsRepository::class, 'db');
$databaseProperty->setValue($repository, $connection);

TenantContext::set(['id' => 'tenant-batch', 'slug' => 'tenant-batch']);
try {
    $values = $repository->getMany([
        'shipping_delivery',
        'shipping_pickup',
        'shipping_missing',
        'shipping_delivery',
        '',
    ]);
} finally {
    TenantContext::clear();
}

$expectedParams = [
    'tenant_id' => 'tenant-batch',
    'key_0' => 'tenant-batch:shipping_delivery',
    'key_1' => 'tenant-batch:shipping_pickup',
    'key_2' => 'tenant-batch:shipping_missing',
];

$root = dirname(__DIR__, 4);
$controller = file_get_contents($root . '/src/Http/Shared/SettingsControllerBase.php');
$shippingMethod = is_string($controller)
    ? preg_replace(
        '/^.*?public function getShipping\(\) \{/s',
        'public function getShipping() {',
        $controller,
        1
    )
    : null;
if (is_string($shippingMethod)) {
    $shippingMethod = preg_replace('/\n    public function updateShipping\(\).*$/s', '', $shippingMethod, 1);
}

$checks = [
    'batch uses one prepared SQL statement' => $connection->prepareCalls === 1,
    'batch SQL preserves the tenant predicate' => str_contains($connection->sql, 'tenant_id = :tenant_id'),
    'batch SQL binds one placeholder per unique key' => substr_count($connection->sql, ':key_') === 3,
    'batch scopes every key to the active tenant' => $connection->statement->params === $expectedParams,
    'batch returns requested values and null for a missing key' => $values === [
        'shipping_delivery' => '5.00',
        'shipping_pickup' => '0.00',
        'shipping_missing' => null,
    ],
    'shipping controller uses the batch API once' => is_string($shippingMethod)
        && substr_count($shippingMethod, '->getMany(') === 1,
    'shipping controller has no sequential setting reads' => is_string($shippingMethod)
        && !str_contains($shippingMethod, '->get('),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Shipping settings batch read failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Shipping settings batch read: OK\n";
