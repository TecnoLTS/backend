<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Infrastructure\Storage\Http\StorageHttpTransport;

final class StorageManager
{
    private static ?self $instance = null;
    private ?ObjectStorage $artifacts = null;
    private ?ObjectStorage $uploads = null;

    public function __construct(
        private readonly StorageConfiguration $configuration,
        private readonly ?StorageHttpTransport $transport = null
    ) {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(StorageConfiguration::fromEnvironment());
    }

    public static function replace(?self $manager): void
    {
        self::$instance = $manager;
    }

    public function configuration(): StorageConfiguration
    {
        return $this->configuration;
    }

    public function artifacts(): ObjectStorage
    {
        return $this->artifacts ??= $this->create(
            scope: 'artifacts',
            localRoot: $this->configuration->localArtifactRoot
        );
    }

    public function uploads(): ObjectStorage
    {
        return $this->uploads ??= $this->create(
            scope: 'uploads',
            localRoot: $this->configuration->localUploadRoot
        );
    }

    private function create(string $scope, string $localRoot): ObjectStorage
    {
        if ($this->configuration->driver === 'local') {
            return $scope === 'uploads'
                ? new LocalObjectStorage($localRoot, 0755, 0644)
                : new LocalObjectStorage($localRoot, 0750, 0640);
        }

        return new S3ObjectStorage($this->configuration, $scope, $this->transport);
    }
}
