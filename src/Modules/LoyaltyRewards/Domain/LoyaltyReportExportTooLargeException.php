<?php

namespace App\Modules\LoyaltyRewards\Domain;

final class LoyaltyReportExportTooLargeException extends \InvalidArgumentException
{
    public function __construct(private readonly int $rowCount, private readonly int $maximumRows)
    {
        parent::__construct(sprintf(
            'El reporte contiene %d filas y supera el maximo permitido de %d.',
            $rowCount,
            $maximumRows
        ));
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function maximumRows(): int
    {
        return $this->maximumRows;
    }
}
