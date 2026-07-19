<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Readiness;

use App\Infrastructure\Storage\StorageManager;
use App\Modules\IdentityPlatform\Application\Ports\RuntimeDependencyReadinessPort;

final class StorageReadinessAdapter implements RuntimeDependencyReadinessPort
{
    private const PROBE_KEY = 'readiness/non-existent-probe';
    private const CACHE_SECONDS = 10;

    /** @var array<string, scalar|null>|null */
    private static ?array $cached = null;
    private static int $cachedAt = 0;

    public function assertReady(): array
    {
        if (self::$cached !== null && time() - self::$cachedAt < self::CACHE_SECONDS) {
            return self::$cached;
        }

        $manager = StorageManager::instance();
        $configuration = $manager->configuration();
        if ($configuration->driver === 'local') {
            foreach ([$configuration->localArtifactRoot, $configuration->localUploadRoot] as $root) {
                if (!is_dir($root) || !is_readable($root) || !is_writable($root)) {
                    throw new \RuntimeException('Storage local no esta disponible para lectura/escritura.');
                }
            }
        } else {
            // HEAD contra una clave inexistente: 404 confirma endpoint, bucket,
            // TLS y credencial sin crear ni modificar objetos.
            $manager->artifacts()->exists(self::PROBE_KEY);
            $manager->uploads()->exists(self::PROBE_KEY);
        }

        self::$cachedAt = time();
        self::$cached = [
            'almacenamiento' => $configuration->driver,
            'almacenamiento_estado' => 'disponible',
        ];

        return self::$cached;
    }
}
