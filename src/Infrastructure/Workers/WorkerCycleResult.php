<?php

declare(strict_types=1);

namespace App\Infrastructure\Workers;

final class WorkerCycleResult
{
    /** @var array{attempted:int,succeeded:int,skipped:int,failed:int,unknown:int} */
    private array $counters;

    /** @param array<string, int|float|string|bool|null> $context */
    public function __construct(
        private readonly string $worker,
        array $counters,
        private readonly array $context = []
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $worker)) {
            throw new \InvalidArgumentException('Nombre de worker invalido.');
        }
        $normalized = [];
        foreach (['attempted', 'succeeded', 'skipped', 'failed', 'unknown'] as $key) {
            $value = $counters[$key] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException("Contador worker invalido: {$key}.");
            }
            $normalized[$key] = $value;
        }
        if ($normalized['succeeded'] + $normalized['skipped'] + $normalized['failed'] + $normalized['unknown'] > $normalized['attempted']) {
            throw new \InvalidArgumentException('Los resultados worker superan intentos.');
        }
        $this->counters = $normalized;
    }

    public function isDegraded(): bool
    {
        return $this->counters['failed'] > 0 || $this->counters['unknown'] > 0;
    }

    public function exitCode(): int
    {
        return $this->isDegraded() ? 1 : 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => 'worker_cycle_result',
            'worker' => $this->worker,
            'status' => $this->isDegraded() ? 'degraded' : 'ok',
            'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'counters' => $this->counters,
            'context' => $this->context,
        ];
    }

    /** @param resource $stream */
    public function emit($stream = STDOUT): void
    {
        fwrite($stream, json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
