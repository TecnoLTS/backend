<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Services\FacturadorApiService;

class BillingDocumentController {
    private function authenticate(): void {
        Auth::requireAdmin();
    }

    public function rides(): void {
        $this->authenticate();

        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $facturador = new FacturadorApiService();
            Response::json($facturador->listRidePdfs($limit));
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BILLING_RIDES_LIST_FAILED');
        }
    }

    public function ridePdf(string $accessKey): void {
        $this->authenticate();

        try {
            $facturador = new FacturadorApiService();
            $pdf = $facturador->getRidePdf($accessKey);
            $content = (string)($pdf['content'] ?? '');
            $filename = (string)($pdf['filename'] ?? 'RIDE.pdf');

            if ($content === '') {
                Response::error('RIDE PDF vacío o no disponible', 404, 'BILLING_RIDE_PDF_EMPTY');
                return;
            }

            Response::noStore();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_RIDE_PDF_INVALID_KEY');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BILLING_RIDE_PDF_FAILED');
        }
    }
}
