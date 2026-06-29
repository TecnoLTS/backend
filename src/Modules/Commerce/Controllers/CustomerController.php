<?php

namespace App\Modules\Commerce\Controllers;

class CustomerController extends \App\Controllers\UserController {
    public function __construct() {
        parent::__construct();
        $this->manageEcommerceCustomers = true;
    }
}
