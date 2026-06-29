<?php

namespace App\Repositories;

use App\Modules\Commerce\Domain\CommerceDomain;

class CustomerRepository extends UserRepository {
    public function __construct() {
        parent::__construct(
            CommerceDomain::KEY,
            '"Customer"',
            '"CustomerAuthSecurityEvent"',
            false
        );
    }
}
