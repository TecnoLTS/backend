<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Infrastructure\Storage\LocalObjectStorage;
use App\Infrastructure\Storage\ObjectStorage;
use App\Infrastructure\Storage\StorageConfiguration;
use App\Infrastructure\Storage\StorageException;
use App\Modules\CatalogInventory\Infrastructure\CatalogImageStorage;

$fail = static function (string $message): never {
    fwrite(STDERR, '[catalog-image-storage-test] ' . $message . PHP_EOL);
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

$temporaryRoot = sys_get_temp_dir() . '/pm-catalog-image-test-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    $fail('No se pudo crear el directorio temporal.');
}

$webp = base64_decode(
    'UklGRooCAABXRUJQVlA4IH4CAAAwJgCdASrcABMBPnk8nEokoyKhodkpCJAPCWlu4XPxBjkes/mlAr0+Huxj7R1Y+0dWPtHVj7R1Y+0dWPtHVj7R1Y+0dWPtHVjbhgYuy2Q4iuEI90F6zvEPaOrHxC92yS3Gu/oCpzCyB/8vxPrvG9no2xe0dP/IJ+4wHTSR0ZuDh4AxbNDsm7fI0uMlls7l3On8cg6ag9CsARXeeGfcc7Vq1RJN4nN99jlh23kX+ShDGzPd0JsrE3JfAlU6FlzbepAqZXpoGAH6KqjgI7JBxoUYbb8YhQo12FtoUy8pR3L4BFEJM5kkYPRJZzlo5LWsym5xjWu95q7+aWse3ZkbsSGjJhKdNtjrar4YtaNJ8dddMiJXPrb7nkb76fD3Yx9o6sfaOrH2jqx9o6sfaOrH2jqx9o6sfaOrH2jp4AD+/2DQAAAAAIU0UiV2vhxk7I458G+u1jhEldHL6X1y1NlTMhUReqffWlkxnq6BxlwmuaUTgtJlGtUDizHMuepowcwdFPctbU5BSNCuG5KgEZfZKULsv5JRLtp4Q9BglIdYpIkzo0dC02g+tuNkT5SC/KzOFTZBFFakSdVwd/ilifzwg6qNumjUoOrlzJJbEfNff4xVae/w5QwDX/oz39K95cYX1OQVNQKQ7qKItaWISY5CEKP2LvAchdJqq6JL5xQb7xcvptuHDiu8pM+yz52RWupM5WTCpnWzXijmwXpicWLIMCxU28INgekzPKGqLKZlR0n0QKphG7BrycLuxrpAjIlOA2tq8Q6obaMEGQmucDRhvBBjKBP4XCu3SuNenVLQ5R+L7cX1ZpSXr9JlER4MvY6SSNxqZ+531AAAAAAAAAAAAA==',
    true
);
if (!is_string($webp)) {
    $fail('Fixture WebP invalido.');
}

$upload = static function (string $path, string $name): array {
    return [
        'name' => $name,
        'type' => 'image/webp',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
};

try {
    $mainName = 'paramascotas-producto-prueba-20260714-a1b2c3d4e5.webp';
    $mainPath = $temporaryRoot . '/main.webp';
    $variant220Path = $temporaryRoot . '/variant-220.webp';
    $variant360Path = $temporaryRoot . '/variant-360.webp';
    file_put_contents($mainPath, $webp);
    file_put_contents($variant220Path, $webp);
    file_put_contents($variant360Path, $webp);

    $localConfiguration = StorageConfiguration::fromEnvironment([
        'APP_ENV' => 'qa',
        'STORAGE_DRIVER' => 'local',
        'REQUIRE_HA' => 'false',
        'STORAGE_LOCAL_ARTIFACT_ROOT' => $temporaryRoot . '/artifacts',
        'STORAGE_LOCAL_UPLOAD_ROOT' => $temporaryRoot . '/uploads',
    ]);
    $localStorage = new LocalObjectStorage($temporaryRoot . '/uploads', 0755, 0644);
    $service = new CatalogImageStorage(
        $localStorage,
        $localConfiguration,
        static fn(string $path): bool => is_file($path)
    );
    $result = $service->store(
        $upload($mainPath, $mainName),
        [
            220 => $upload($variant220Path, str_replace('.webp', '-220.webp', $mainName)),
            360 => $upload($variant360Path, str_replace('.webp', '-360.webp', $mainName)),
        ],
        'products',
        $mainName,
        'paramascotasec'
    );
    $expectedKey = 'tenants/paramascotasec/products/' . $mainName;
    $assert($result['storageKey'] === $expectedKey, 'La clave no quedo aislada por tenant y dominio.');
    $assert($result['url'] === '/uploads/' . $expectedKey, 'QA local no devolvio URL /uploads.');
    $assert($localStorage->exists($expectedKey), 'No se guardo la imagen principal.');
    $assert($localStorage->exists(str_replace('.webp', '-220.webp', $expectedKey)), 'Falta variante 220.');
    $assert($localStorage->exists(str_replace('.webp', '-360.webp', $expectedKey)), 'Falta variante 360.');

    $badPath = $temporaryRoot . '/bad.webp';
    file_put_contents($badPath, 'not-a-webp');
    $invalidRejected = false;
    try {
        $service->store($upload($badPath, 'archivo-invalido.webp'), [], 'brands', 'archivo-invalido.webp', 'paramascotasec');
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    $assert($invalidRejected, 'Se acepto un archivo sin firma WebP.');

    $memoryStorage = new class implements ObjectStorage {
        /** @var array<string, string> */
        public array $objects = [];
        public int $puts = 0;
        public int $failOnPut = 0;
        public function driver(): string { return 's3'; }
        public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): array {
            $this->puts++;
            if ($this->failOnPut > 0 && $this->puts === $this->failOnPut) {
                throw new StorageException('Falla simulada.');
            }
            $this->objects[$key] = $contents;
            return ['key' => $key, 'size' => strlen($contents), 'content_type' => $contentType, 'etag' => null, 'modified_at' => null];
        }
        public function get(string $key): string { return $this->objects[$key] ?? throw new StorageException('No encontrado.'); }
        public function exists(string $key): bool { return array_key_exists($key, $this->objects); }
        public function metadata(string $key): ?array { return $this->exists($key) ? ['key' => $key, 'size' => strlen($this->objects[$key]), 'content_type' => 'image/webp', 'etag' => null, 'modified_at' => null] : null; }
        public function delete(string $key): void { unset($this->objects[$key]); }
        public function materialize(string $key): string { throw new StorageException('No usado.'); }
        public function localPath(string $key): ?string { return null; }
    };
    $s3Configuration = StorageConfiguration::fromEnvironment([
        'APP_ENV' => 'production',
        'STORAGE_DRIVER' => 's3',
        'REQUIRE_HA' => 'true',
        'OBJECT_STORAGE_ENDPOINT' => 'https://objects.example.test',
        'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'https://cdn.example.test/catalog',
        'OBJECT_STORAGE_BUCKET' => 'pm-uploads',
        'OBJECT_STORAGE_REGION' => 'us-east-1',
        'OBJECT_STORAGE_ACCESS_KEY' => 'fake-access',
        'OBJECT_STORAGE_SECRET_KEY' => 'fake-secret',
        'STORAGE_TEMP_ROOT' => $temporaryRoot . '/materialized',
    ]);
    $s3Service = new CatalogImageStorage(
        $memoryStorage,
        $s3Configuration,
        static fn(string $path): bool => is_file($path)
    );
    $brandName = 'marca-prueba-20260714-a1b2c3d4e5.webp';
    $brandResult = $s3Service->store(
        $upload($mainPath, $brandName),
        [],
        'brands',
        $brandName,
        'tenant-a'
    );
    $assert(
        $brandResult['url'] === 'https://cdn.example.test/catalog/tenants/tenant-a/brands/' . $brandName,
        'Produccion no devolvio la URL publica del object storage.'
    );

    $memoryStorage->objects = [];
    $memoryStorage->puts = 0;
    $memoryStorage->failOnPut = 2;
    $cleanupFailed = false;
    try {
        $s3Service->store(
            $upload($mainPath, $mainName),
            [
                220 => $upload($variant220Path, str_replace('.webp', '-220.webp', $mainName)),
                360 => $upload($variant360Path, str_replace('.webp', '-360.webp', $mainName)),
            ],
            'products',
            $mainName,
            'tenant-b'
        );
    } catch (StorageException) {
        $cleanupFailed = true;
    }
    $assert($cleanupFailed, 'La falla parcial simulada no se propago.');
    $assert($memoryStorage->objects === [], 'La falla parcial dejo objetos publicados.');

    fwrite(STDOUT, "Catalog image storage: OK\n");
} finally {
    $removeTree($temporaryRoot);
}
