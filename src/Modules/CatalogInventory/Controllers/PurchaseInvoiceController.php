<?php

namespace App\Modules\CatalogInventory\Controllers;

use App\Core\Response;
use App\Repositories\PurchaseInvoiceRepository;

class PurchaseInvoiceController {
    private $repository;

    public function __construct() {
        $this->repository = new PurchaseInvoiceRepository();
    }

    public function index() {
        try {
            $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 100;
            Response::json($this->repository->listRecent($limit));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PURCHASE_INVOICES_LIST_FAILED');
        }
    }

    public function show($id) {
        try {
            $invoice = $this->repository->getById((string)$id);
            if (!$invoice) {
                Response::error('Factura de compra no encontrada', 404, 'PURCHASE_INVOICE_NOT_FOUND');
                return;
            }
            Response::json($invoice);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PURCHASE_INVOICE_FETCH_FAILED');
        }
    }
}
