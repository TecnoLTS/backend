<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Controllers;

use App\Core\Response;

/**
 * Cheap public liveness contract. Readiness of databases and dependencies is
 * attested separately; this endpoint must not construct the domain repository.
 */
final class LoyaltyHealthController
{
    public function health(): void
    {
        Response::json(['status' => 'ok', 'module' => 'loyalty-rewards']);
    }
}
