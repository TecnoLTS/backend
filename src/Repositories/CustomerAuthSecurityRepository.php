<?php

namespace App\Repositories;

use App\Modules\Commerce\Domain\CommerceDomain;

class CustomerAuthSecurityRepository extends AuthSecurityRepository {
    public function __construct() {
        parent::__construct(CommerceDomain::KEY, '"CustomerAuthSecurityEvent"');
    }
}
