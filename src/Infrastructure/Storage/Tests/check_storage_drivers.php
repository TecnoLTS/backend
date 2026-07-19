<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use App\Infrastructure\Storage\Http\StorageHttpResponse;
use App\Infrastructure\Storage\Http\StorageHttpTransport;
use App\Infrastructure\Storage\LocalObjectStorage;
use App\Infrastructure\Storage\S3ObjectStorage;
use App\Infrastructure\Storage\StorageConfiguration;
use App\Infrastructure\Storage\StorageException;

$fail = static function (string $message): never {
    fwrite(STDERR, '[storage-test] ' . $message . PHP_EOL);
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$temporaryRoot = sys_get_temp_dir() . '/pm-storage-test-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    $fail('No se pudo crear el directorio temporal.');
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};

try {
    $local = new LocalObjectStorage($temporaryRoot . '/local');
    $local->put('billing/xml/test.xml', '<ok/>', 'application/xml');
    $assert($local->get('billing/xml/test.xml') === '<ok/>', 'El round-trip local no conservó el contenido.');
    $assert(($local->metadata('billing/xml/test.xml')['size'] ?? -1) === 5, 'Metadata local incorrecta.');
    $assert(is_file($local->materialize('billing/xml/test.xml')), 'Materialización local inexistente.');
    $local->delete('billing/xml/test.xml');
    $assert(!$local->exists('billing/xml/test.xml'), 'Delete local no eliminó el objeto.');

    $traversalRejected = false;
    try {
        $local->put('../escape', 'bad');
    } catch (StorageException) {
        $traversalRejected = true;
    }
    $assert($traversalRejected, 'El driver local aceptó path traversal.');

    $haRejected = false;
    try {
        StorageConfiguration::fromEnvironment([
            'APP_ENV' => 'qa',
            'STORAGE_DRIVER' => 'local',
            'REQUIRE_HA' => 'true',
            'STORAGE_LOCAL_ARTIFACT_ROOT' => $temporaryRoot . '/artifacts',
            'STORAGE_LOCAL_UPLOAD_ROOT' => $temporaryRoot . '/uploads',
        ]);
    } catch (StorageException) {
        $haRejected = true;
    }
    $assert($haRejected, 'REQUIRE_HA=true aceptó el driver local.');

    $transport = new class implements StorageHttpTransport {
        /** @var array<string, string> */
        public array $objects = [];
        /** @var list<array{method: string, url: string, headers: array<string, string>}> */
        public array $requests = [];

        public function request(
            string $method,
            string $url,
            array $headers,
            string $body,
            int $timeoutSeconds,
            bool $verifyTls
        ): StorageHttpResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($method === 'PUT') {
                $this->objects[$path] = $body;
                return new StorageHttpResponse(200, ['etag' => '"fake-etag"'], '');
            }
            if ($method === 'DELETE') {
                unset($this->objects[$path]);
                return new StorageHttpResponse(204, [], '');
            }
            if (!array_key_exists($path, $this->objects)) {
                return new StorageHttpResponse(404, [], '');
            }
            if ($method === 'HEAD') {
                return new StorageHttpResponse(200, [
                    'content-length' => (string) strlen($this->objects[$path]),
                    'content-type' => 'application/xml',
                    'last-modified' => 'Tue, 14 Jul 2026 12:00:00 GMT',
                    'etag' => '"fake-etag"',
                ], '');
            }

            return new StorageHttpResponse(200, ['content-type' => 'application/xml'], $this->objects[$path]);
        }
    };

    $s3Configuration = StorageConfiguration::fromEnvironment([
        'APP_ENV' => 'production',
        'STORAGE_DRIVER' => 's3',
        'REQUIRE_HA' => 'true',
        'OBJECT_STORAGE_ENDPOINT' => 'https://objects.example.test:9443/minio',
        'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'https://cdn.example.test/catalog',
        'OBJECT_STORAGE_BUCKET' => 'pm-artifacts',
        'OBJECT_STORAGE_REGION' => 'us-east-1',
        'OBJECT_STORAGE_ACCESS_KEY' => 'fake-access-key',
        'OBJECT_STORAGE_SECRET_KEY' => 'fake-secret-key',
        'OBJECT_STORAGE_PREFIX' => 'paramascotasec/test',
        'OBJECT_STORAGE_PATH_STYLE' => 'true',
        'OBJECT_STORAGE_TLS_VERIFY' => 'true',
        'STORAGE_TEMP_ROOT' => $temporaryRoot . '/materialized',
    ]);
    $s3 = new S3ObjectStorage($s3Configuration, 'artifacts', $transport);
    $billing = new BillingArtifactStorage($s3, 'paramascotasec');
    $accessKey = str_repeat('1', 49);
    $reference = $billing->putXml('firmados', $accessKey, '<factura/>');
    $assert($reference === 'storage://artifacts/billing/tenants/paramascotasec/xml/firmados/' . $accessKey . '.xml', 'Referencia S3 tenantizada incorrecta.');
    $assert($billing->read($reference) === '<factura/>', 'El round-trip S3 simulado no conservó el contenido.');
    $assert(($billing->metadata($reference)['size'] ?? -1) === 10, 'Metadata S3 simulada incorrecta.');
    $materialized = $billing->materialize($reference);
    $assert(is_file($materialized) && file_get_contents($materialized) === '<factura/>', 'Materialización S3 simulada incorrecta.');

    $firstRequest = $transport->requests[0] ?? null;
    $authorization = is_array($firstRequest) ? ($firstRequest['headers']['Authorization'] ?? '') : '';
    $assert(str_starts_with($authorization, 'AWS4-HMAC-SHA256 Credential=fake-access-key/'), 'La petición S3 no fue firmada con SigV4.');
    $assert(str_contains((string) ($firstRequest['url'] ?? ''), '/minio/pm-artifacts/paramascotasec/test/artifacts/billing/tenants/paramascotasec/'), 'La URL path-style no preservó tenant, bucket, prefijo y scope.');
    $assert(!str_contains($authorization, 'fake-secret-key'), 'La firma expuso la clave secreta.');

    $insecurePublicBaseRejected = false;
    try {
        StorageConfiguration::fromEnvironment([
            'APP_ENV' => 'production',
            'STORAGE_DRIVER' => 's3',
            'REQUIRE_HA' => 'true',
            'OBJECT_STORAGE_ENDPOINT' => 'https://objects.example.test',
            'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'http://cdn.example.test/catalog',
            'OBJECT_STORAGE_BUCKET' => 'pm-artifacts',
            'OBJECT_STORAGE_REGION' => 'us-east-1',
            'OBJECT_STORAGE_ACCESS_KEY' => 'fake-access-key',
            'OBJECT_STORAGE_SECRET_KEY' => 'fake-secret-key',
            'STORAGE_TEMP_ROOT' => $temporaryRoot . '/materialized-insecure',
        ]);
    } catch (StorageException) {
        $insecurePublicBaseRejected = true;
    }
    $assert($insecurePublicBaseRejected, 'Se acepto OBJECT_STORAGE_PUBLIC_BASE_URL sin HTTPS.');

    $missingPublicBaseRejected = false;
    try {
        StorageConfiguration::fromEnvironment([
            'APP_ENV' => 'production',
            'STORAGE_DRIVER' => 's3',
            'REQUIRE_HA' => 'true',
            'OBJECT_STORAGE_ENDPOINT' => 'https://objects.example.test',
            'OBJECT_STORAGE_BUCKET' => 'pm-artifacts',
            'OBJECT_STORAGE_REGION' => 'us-east-1',
            'OBJECT_STORAGE_ACCESS_KEY' => 'fake-access-key',
            'OBJECT_STORAGE_SECRET_KEY' => 'fake-secret-key',
            'STORAGE_TEMP_ROOT' => $temporaryRoot . '/materialized-missing-public-base',
        ]);
    } catch (StorageException) {
        $missingPublicBaseRejected = true;
    }
    $assert($missingPublicBaseRejected, 'S3 acepto una configuracion sin OBJECT_STORAGE_PUBLIC_BASE_URL.');

    fwrite(STDOUT, "Storage drivers: OK\n");
} finally {
    $removeTree($temporaryRoot);
}
