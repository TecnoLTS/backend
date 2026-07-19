<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Application\Ports;

use Countable;
use InvalidArgumentException;

/**
 * Typed boundary value returned by purchase-source adapters.
 */
final readonly class PurchaseSourceMatches implements Countable
{
    /** @var list<array<string, mixed>> */
    private array $records;

    /** @param list<array<string, mixed>> $records */
    public function __construct(array $records)
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Cada coincidencia de compra debe ser un registro estructurado.');
            }
        }

        $this->records = array_values($records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** @return array<string, mixed>|null */
    public function single(): ?array
    {
        return count($this->records) === 1 ? $this->records[0] : null;
    }
}
