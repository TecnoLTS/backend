<?php

namespace App\Modules\Commerce\Controllers;

use App\Http\Shared\ManagedUserControllerBase;

class CustomerController extends ManagedUserControllerBase
{
    public function __construct()
    {
        parent::__construct();
        $this->manageEcommerceCustomers = true;
    }
}
