<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

interface CommerceTaxConfigurationPort
{
    /** @return array{rate:float,credit_current_rate:float,credit_carryforward_rate:float,revision:int} */
    public function getTaxConfiguration(): array;

    /** @return array{rate:float,credit_current_rate:float,credit_carryforward_rate:float,revision:int,applied:bool,projection_synchronized:bool} */
    public function updateTaxConfiguration(
        float $rate,
        float $creditCurrentRate,
        float $creditCarryforwardRate,
        string $actorUserId,
        int $expectedRevision
    ): array;
}
