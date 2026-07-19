<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

final class StorageConfiguration
{
    public function __construct(
        public readonly string $driver,
        public readonly bool $requireHa,
        public readonly string $localArtifactRoot,
        public readonly string $localUploadRoot,
        public readonly string $endpoint,
        public readonly string $publicBaseUrl,
        public readonly string $bucket,
        public readonly string $region,
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly string $sessionToken,
        public readonly string $prefix,
        public readonly bool $pathStyle,
        public readonly bool $verifyTls,
        public readonly int $timeoutSeconds,
        public readonly string $temporaryRoot
    ) {
        $this->assertValid();
    }

    /** @param array<string, mixed>|null $environment */
    public static function fromEnvironment(?array $environment = null): self
    {
        $environment ??= [];
        $appEnv = strtolower(self::value('APP_ENV', 'qa', $environment));
        $explicitDriver = self::value('STORAGE_DRIVER', '', $environment);
        if ($explicitDriver === '') {
            $explicitDriver = self::value('OBJECT_STORAGE_DRIVER', '', $environment);
        }
        $driver = strtolower($explicitDriver !== ''
            ? $explicitDriver
            : ($appEnv === 'production' || $appEnv === 'prod' ? 's3' : 'local'));

        return new self(
            driver: $driver,
            requireHa: self::boolValue('REQUIRE_HA', false, $environment),
            localArtifactRoot: self::value('STORAGE_LOCAL_ARTIFACT_ROOT', '/var/www/html/storage', $environment),
            localUploadRoot: self::value('STORAGE_LOCAL_UPLOAD_ROOT', '/var/www/html/public/uploads', $environment),
            endpoint: rtrim(self::value('OBJECT_STORAGE_ENDPOINT', '', $environment), '/'),
            publicBaseUrl: rtrim(self::value('OBJECT_STORAGE_PUBLIC_BASE_URL', '', $environment), '/'),
            bucket: self::value('OBJECT_STORAGE_BUCKET', '', $environment),
            region: self::value('OBJECT_STORAGE_REGION', 'us-east-1', $environment),
            accessKey: self::secretValue('OBJECT_STORAGE_ACCESS_KEY', $environment),
            secretKey: self::secretValue('OBJECT_STORAGE_SECRET_KEY', $environment),
            sessionToken: self::secretValue('OBJECT_STORAGE_SESSION_TOKEN', $environment),
            prefix: trim(self::value('OBJECT_STORAGE_PREFIX', 'paramascotasec', $environment), '/'),
            pathStyle: self::boolValue('OBJECT_STORAGE_PATH_STYLE', true, $environment),
            verifyTls: self::boolValue('OBJECT_STORAGE_TLS_VERIFY', true, $environment),
            timeoutSeconds: max(1, min(120, (int) self::value('OBJECT_STORAGE_TIMEOUT_SECONDS', '15', $environment))),
            temporaryRoot: self::value('STORAGE_TEMP_ROOT', '/tmp/backend-object-storage', $environment)
        );
    }

    /** @return array{driver: string, require_ha: bool, endpoint_configured: bool, public_base_url_configured: bool, bucket_configured: bool, credentials_configured: bool} */
    public function summary(): array
    {
        return [
            'driver' => $this->driver,
            'require_ha' => $this->requireHa,
            'endpoint_configured' => $this->endpoint !== '',
            'public_base_url_configured' => $this->publicBaseUrl !== '',
            'bucket_configured' => $this->bucket !== '',
            'credentials_configured' => $this->accessKey !== '' && $this->secretKey !== '',
        ];
    }

    private function assertValid(): void
    {
        if (!in_array($this->driver, ['local', 's3'], true)) {
            throw new StorageException('STORAGE_DRIVER debe ser local o s3.');
        }
        if ($this->requireHa && $this->driver === 'local') {
            throw new StorageException('REQUIRE_HA=true prohíbe STORAGE_DRIVER=local; configure un almacenamiento S3-compatible.');
        }
        if ($this->driver === 'local') {
            $this->assertAbsoluteRoot($this->localArtifactRoot, 'STORAGE_LOCAL_ARTIFACT_ROOT');
            $this->assertAbsoluteRoot($this->localUploadRoot, 'STORAGE_LOCAL_UPLOAD_ROOT');
            return;
        }

        $parts = parse_url($this->endpoint);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new StorageException('OBJECT_STORAGE_ENDPOINT debe ser una URL HTTP(S) sin credenciales, query ni fragmento.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket) !== 1) {
            throw new StorageException('OBJECT_STORAGE_BUCKET no tiene un nombre S3 válido.');
        }
        if ($this->region === '' || preg_match('/^[a-z0-9-]+$/', $this->region) !== 1) {
            throw new StorageException('OBJECT_STORAGE_REGION no es válida.');
        }
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new StorageException('El driver s3 requiere OBJECT_STORAGE_ACCESS_KEY y OBJECT_STORAGE_SECRET_KEY (o sus variantes _FILE).');
        }
        $publicParts = parse_url($this->publicBaseUrl);
        if (!is_array($publicParts)
            || strtolower((string) ($publicParts['scheme'] ?? '')) !== 'https'
            || trim((string) ($publicParts['host'] ?? '')) === ''
            || isset($publicParts['user'])
            || isset($publicParts['pass'])
            || isset($publicParts['query'])
            || isset($publicParts['fragment'])) {
            throw new StorageException('OBJECT_STORAGE_PUBLIC_BASE_URL debe ser una URL HTTPS publica sin credenciales, query ni fragmento.');
        }
        if ($this->prefix !== '') {
            StorageKey::normalize($this->prefix . '/probe');
        }
        $this->assertAbsoluteRoot($this->temporaryRoot, 'STORAGE_TEMP_ROOT');
    }

    private function assertAbsoluteRoot(string $path, string $name): void
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new StorageException(sprintf('%s debe ser una ruta absoluta.', $name));
        }
    }

    /** @param array<string, mixed> $environment */
    private static function secretValue(string $name, array $environment): string
    {
        $direct = self::value($name, '', $environment);
        if ($direct !== '') {
            return $direct;
        }

        $file = self::value($name . '_FILE', '', $environment);
        if ($file === '') {
            return '';
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new StorageException(sprintf('No se puede leer el archivo configurado en %s_FILE.', $name));
        }

        $contents = file_get_contents($file, false, null, 0, 65537);
        if (!is_string($contents) || strlen($contents) > 65536) {
            throw new StorageException(sprintf('El archivo configurado en %s_FILE es inválido.', $name));
        }

        return trim($contents);
    }

    /** @param array<string, mixed> $environment */
    private static function value(string $name, string $default, array $environment): string
    {
        if (array_key_exists($name, $environment)) {
            $value = $environment[$name];
            return is_scalar($value) ? trim((string) $value) : $default;
        }
        if (array_key_exists($name, $_ENV)) {
            return trim((string) $_ENV[$name]);
        }
        $value = getenv($name);

        return $value === false ? $default : trim((string) $value);
    }

    /** @param array<string, mixed> $environment */
    private static function boolValue(string $name, bool $default, array $environment): bool
    {
        $raw = strtolower(self::value($name, $default ? 'true' : 'false', $environment));
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new StorageException(sprintf('%s debe ser true o false.', $name));
        }

        return $value;
    }
}
