<?php

namespace App\Repositories;

use App\Modules\Commerce\Domain\CommerceDomain;

class CustomerPasswordResetTokenRepository extends PasswordResetTokenRepository {
    public function __construct() {
        parent::__construct(CommerceDomain::KEY, '"CustomerPasswordResetToken"');
    }
}
