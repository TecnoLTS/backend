<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Infrastructure\Storage\PublicUploadUrlResolver;
use App\Infrastructure\Storage\StorageConfiguration;

$fail = static function (string $message): never {
    fwrite(STDERR, '[public-upload-url-test] ' . $message . PHP_EOL);
    exit(1);
};
$assertSame = static function (string $expected, string $actual, string $message) use ($fail): void {
    if (!hash_equals($expected, $actual)) {
        $fail($message);
    }
};

$local = new PublicUploadUrlResolver(StorageConfiguration::fromEnvironment([
    'APP_ENV' => 'qa',
    'STORAGE_DRIVER' => 'local',
    'STORAGE_LOCAL_ARTIFACT_ROOT' => '/tmp/artifacts',
    'STORAGE_LOCAL_UPLOAD_ROOT' => '/tmp/uploads',
]));
$assertSame(
    '/uploads/tenants/acme/products/a.webp',
    $local->resolve('/uploads/tenants/acme/products/a.webp'),
    'El driver local altero una URL historica.'
);

$s3 = new PublicUploadUrlResolver(StorageConfiguration::fromEnvironment([
    'APP_ENV' => 'production',
    'STORAGE_DRIVER' => 's3',
    'REQUIRE_HA' => 'true',
    'OBJECT_STORAGE_ENDPOINT' => 'https://objects.example.test',
    'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'https://cdn.example.test/paramascotasec/uploads',
    'OBJECT_STORAGE_BUCKET' => 'pm-artifacts',
    'OBJECT_STORAGE_REGION' => 'us-east-1',
    'OBJECT_STORAGE_ACCESS_KEY' => 'test-access',
    'OBJECT_STORAGE_SECRET_KEY' => 'test-secret',
    'OBJECT_STORAGE_PREFIX' => 'paramascotasec',
    'STORAGE_TEMP_ROOT' => '/tmp/object-storage',
]));
$assertSame(
    'https://cdn.example.test/paramascotasec/uploads/tenants/acme/products/a.webp',
    $s3->resolve('/uploads/tenants/acme/products/a.webp'),
    'S3 no tradujo la URL historica al prefijo CDN.'
);
$assertSame(
    'https://images.example.test/a.webp',
    $s3->resolve('https://images.example.test/a.webp'),
    'El resolver altero una URL externa.'
);
$assertSame(
    '/api/loyalty/rewards/image/token',
    $s3->resolve('/api/loyalty/rewards/image/token'),
    'El resolver altero una ruta API protegida.'
);

fwrite(STDOUT, "Public upload URL compatibility: OK\n");
