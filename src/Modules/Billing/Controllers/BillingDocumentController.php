<?php

namespace App\Modules\Billing\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\Billing\Application\BillingGateway;
use App\Modules\Billing\Application\Ports\BillingOrderAccountingPort;
use App\Modules\Billing\Application\Ports\BillingProductCatalogPort;
use App\Modules\Billing\Infrastructure\BillingCommercePortsFactory;
use App\Modules\Billing\Infrastructure\BillingGatewayFactory;
use App\Services\BillingApiException;
use App\Shared\Tax\EcuadorSriVatCatalog;

class BillingDocumentController {
    private const CERTIFICATE_MAX_BYTES = 10485760;

    private ?BillingProductCatalogPort $productCatalog;
    private ?BillingOrderAccountingPort $orderAccounting;

    public function __construct(
        ?BillingProductCatalogPort $productCatalog = null,
        ?BillingOrderAccountingPort $orderAccounting = null
    ) {
        $this->productCatalog = $productCatalog;
        $this->orderAccounting = $orderAccounting;
    }

    private function productCatalog(): BillingProductCatalogPort {
        return $this->productCatalog ??= BillingCommercePortsFactory::products();
    }

    private function orderAccounting(): BillingOrderAccountingPort {
        return $this->orderAccounting ??= BillingCommercePortsFactory::orders();
    }

    private function billingGateway(): BillingGateway {
        return BillingGatewayFactory::create();
    }

    private function authenticate(): void {
        Auth::requireAdmin();
    }

    public function health(): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            Response::json($billing->health());
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_HEALTH_FAILED', 502);
        }
    }

    public function configuration(): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            Response::json($billing->configuration($this->queryAmbiente()));
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_CONFIGURATION_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_CONFIGURATION_FAILED');
        }
    }

    public function updateConfiguration(): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $billing = $this->billingGateway();
            Response::json($billing->updateConfiguration($data, $this->queryAmbiente()));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_CONFIGURATION_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_CONFIGURATION_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_CONFIGURATION_SAVE_FAILED');
        }
    }

    public function createBranch(): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $billing = $this->billingGateway();
            Response::json($billing->createBranch($data, $this->queryAmbiente()), 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_BRANCH_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_BRANCH_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_BRANCH_CREATE_FAILED');
        }
    }

    public function updateBranch(string $branchId): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $billing = $this->billingGateway();
            Response::json($billing->updateBranch((int)$branchId, $data, $this->queryAmbiente()));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_BRANCH_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_BRANCH_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_BRANCH_UPDATE_FAILED');
        }
    }

    public function uploadCertificate(): void {
        $this->authenticate();

        try {
            $upload = is_array($_FILES['certificate'] ?? null) ? $_FILES['certificate'] : null;
            if ($upload === null || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                Response::error('Selecciona un archivo .p12 para cargar.', 400, 'BILLING_CERTIFICATE_REQUIRED');
                return;
            }

            $tmpName = (string)($upload['tmp_name'] ?? '');
            $fileName = basename((string)($upload['name'] ?? 'certificado.p12'));
            $password = trim((string)($_POST['certificate_password'] ?? $_POST['password'] ?? ''));
            if ($password === '') {
                Response::error('Ingresa la contraseña del certificado .p12.', 400, 'BILLING_CERTIFICATE_PASSWORD_REQUIRED');
                return;
            }
            if (!preg_match('/\.p12$/i', $fileName)) {
                Response::error('El certificado debe tener extension .p12.', 400, 'BILLING_CERTIFICATE_INVALID_EXTENSION');
                return;
            }
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0 || $size > self::CERTIFICATE_MAX_BYTES) {
                Response::error('El certificado .p12 debe pesar hasta 10 MB.', 400, 'BILLING_CERTIFICATE_TOO_LARGE');
                return;
            }
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                Response::error('El certificado no llego correctamente al servidor.', 400, 'BILLING_CERTIFICATE_UPLOAD_INVALID');
                return;
            }

            $billing = $this->billingGateway();
            Response::json($billing->uploadCertificate($tmpName, $fileName, $password, $this->queryAmbiente()));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_CERTIFICATE_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_CERTIFICATE_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_CERTIFICATE_UPLOAD_FAILED');
        }
    }

    public function emit(): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $billing = $this->billingGateway();
            Response::json($billing->emitInvoice($data), 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_EMIT_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_EMIT_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_EMIT_FAILED');
        }
    }

    public function products(): void {
        $this->authenticate();

        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 12)));
            $products = $this->productCatalog()->search($search, $limit + 1, [
                'includeUnpublished' => true,
                'includeProcurement' => false,
                'includeOutOfStock' => true,
            ]);

            $hasMore = count($products) > $limit;
            $data = [];
            foreach (array_slice($products, 0, $limit) as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $data[] = $this->normalizeBillingProduct($product);
            }

            Response::json($data, 200, [
                'source' => $this->billingProductSource(),
                'ecommerce_enabled' => $this->tenantHasEcommerce(),
                'query' => $search,
                'limit' => $limit,
                'returned' => count($data),
                'has_more' => $hasMore,
            ]);
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_PRODUCTS_LIST_FAILED');
        }
    }

    public function storeProduct(): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                Response::error('Nombre del producto requerido.', 400, 'BILLING_PRODUCT_NAME_REQUIRED');
                return;
            }

            $sku = strtoupper(trim((string)($data['sku'] ?? '')));
            $productCatalog = $this->productCatalog();
            if ($sku !== '' && $productCatalog->skuExists($sku)) {
                Response::error('Ya existe un producto con ese SKU.', 409, 'BILLING_PRODUCT_SKU_DUPLICATED');
                return;
            }

            $legacyTaxExempt = $this->boolValue($data['tax_exempt'] ?? false);
            $rawTreatment = $data['tax_treatment'] ?? null;
            $treatment = EcuadorSriVatCatalog::normalizeTreatment($rawTreatment);
            if ($treatment === null && $legacyTaxExempt) {
                $treatment = EcuadorSriVatCatalog::TREATMENT_EXEMPT;
            }
            $rawRate = $data['tax_rate'] ?? null;
            if ($rawRate === null || $rawRate === '') {
                if (!in_array($treatment, [
                    EcuadorSriVatCatalog::TREATMENT_ZERO_RATED,
                    EcuadorSriVatCatalog::TREATMENT_EXEMPT,
                ], true)) {
                    throw new \InvalidArgumentException('tax_rate is required when creating a taxed Billing product.');
                }
                $rawRate = 0;
            }
            if ($legacyTaxExempt && $rawTreatment === null) {
                $rawRate = 0;
            }
            $taxRate = EcuadorSriVatCatalog::assertSupportedRate($rawRate);
            $treatment ??= EcuadorSriVatCatalog::inferTreatment($taxRate);
            EcuadorSriVatCatalog::percentageCode($taxRate, $treatment);
            $priceGross = round(max(0, $this->number($data['price_gross'] ?? $data['price'] ?? 0)), 2);
            $priceNet = $this->splitGrossAmountByTaxRate($priceGross, $taxRate)['net'];
            $cost = round(max(0, $this->number($data['cost'] ?? 0)), 2);
            $initialStock = max(0, (int)$this->number($data['quantity'] ?? $data['stock'] ?? 0));
            $attributes = [
                'sku' => $sku !== '' ? $sku : strtoupper('BILL-' . substr(md5($name . microtime(true)), 0, 8)),
                'auxCode' => trim((string)($data['code_aux'] ?? $data['aux_code'] ?? '')),
                'species' => trim((string)($data['species'] ?? 'General')),
                'unitMeasure' => trim((string)($data['unit_measure'] ?? $data['unitMeasure'] ?? 'unidad')) ?: 'unidad',
                'taxRate' => (string)$taxRate,
                'taxExempt' => $treatment === EcuadorSriVatCatalog::TREATMENT_EXEMPT ? 'true' : 'false',
                'taxTreatment' => $treatment,
                'billingActive' => $this->boolValue($data['active'] ?? true) ? 'true' : 'false',
                'billingOnly' => $this->tenantHasEcommerce() ? 'true' : 'false',
                'sourceModule' => 'billing-sri',
            ];

            $product = $productCatalog->create([
                'name' => $name,
                'category' => trim((string)($data['category'] ?? 'Facturacion')),
                'productType' => trim((string)($data['product_type'] ?? $data['productType'] ?? 'accesorios')),
                'gender' => trim((string)($data['gender'] ?? 'Unisex')),
                'brand' => trim((string)($data['brand'] ?? 'Generico')) ?: 'Generico',
                'price' => $priceNet,
                'originPrice' => $priceNet,
                'cost' => $cost,
                'quantity' => 0,
                'description' => trim((string)($data['description'] ?? $name)),
                'published' => false,
                'image' => $this->productImagePayload($data, $name),
                'attributes' => $attributes,
            ]);

            if ($initialStock > 0 && is_array($product)) {
                $updated = $productCatalog->update((string)$product['id'], [
                    'quantity' => $initialStock,
                    'inventoryAction' => 'adjustment',
                    'inventoryAdjustmentReason' => 'Stock inicial registrado desde Billing SRI',
                ]);
                if (is_array($updated)) {
                    $product = $updated;
                }
            }

            Response::json($this->normalizeBillingProduct($product), 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_PRODUCT_INVALID_PAYLOAD');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_PRODUCT_CREATE_FAILED');
        }
    }

    public function emitFromProducts(): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody();
            $prepared = $this->buildInvoicePayloadFromProducts($data);
            $billing = $this->billingGateway();
            $result = $billing->emitInvoice($prepared['payload']);
            $movements = $this->consumeBillingInventory($prepared['lines'], $prepared['source_reference']);

            Response::json([
                ...$result,
                'source_reference' => $prepared['source_reference'],
                'stock_movements' => $movements,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_PRODUCTS_INVOICE_INVALID_PAYLOAD');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_PRODUCTS_INVOICE_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_PRODUCTS_INVOICE_FAILED');
        }
    }

    public function rides(): void {
        $this->authenticate();

        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $includeCancelled = filter_var($_GET['include_cancelled'] ?? $_GET['includeCancelled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $billing = $this->billingGateway();
            Response::json($this->enrichRidesWithAccountingDates($billing->listRidePdfs($limit, $includeCancelled)));
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_RIDES_LIST_FAILED');
        }
    }

    public function source(string $sourceReference): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            $ride = $billing->findRideBySourceReference(rawurldecode($sourceReference));
            if (!is_array($ride)) {
                Response::error('No existe una factura activa para la referencia indicada.', 404, 'BILLING_SOURCE_NOT_FOUND');
                return;
            }

            $enriched = $this->enrichRidesWithAccountingDates([$ride]);
            Response::json($enriched[0] ?? $ride);
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_SOURCE_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_SOURCE_FAILED');
        }
    }

    public function status(string $accessKey): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            Response::json($billing->getInvoiceStatus($accessKey, $this->queryAmbiente()));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_STATUS_INVALID_KEY');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_STATUS_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_STATUS_FAILED');
        }
    }

    public function xml(string $accessKey): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            $xml = $billing->getInvoiceXml($accessKey, $this->queryAmbiente());
            $content = (string)($xml['content'] ?? '');
            $filename = (string)($xml['filename'] ?? $accessKey . '.xml');

            if (trim($content) === '') {
                Response::error(
                    $this->fiscalDocumentUnavailableMessage('xml'),
                    404,
                    'BILLING_XML_NOT_AVAILABLE',
                    $this->fiscalDocumentUnavailableDetails('xml')
                );
                return;
            }

            Response::noStore();
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_XML_INVALID_KEY');
        } catch (BillingApiException $e) {
            Response::error(
                $this->fiscalDocumentErrorMessage('xml', $e),
                $this->fiscalDocumentStatus($e),
                $this->fiscalDocumentErrorCode('xml', $e),
                $this->fiscalDocumentErrorDetails('xml', $e)
            );
        } catch (\Throwable $e) {
            Response::error('No se pudo preparar el XML autorizado. Consulta el estado del comprobante o intenta nuevamente mas tarde.', 500, 'BILLING_XML_FAILED');
        }
    }

    public function ridePdf(string $accessKey): void {
        $this->authenticate();

        try {
            $billing = $this->billingGateway();
            $pdf = $billing->getRidePdf($accessKey);
            $content = (string)($pdf['content'] ?? '');
            $filename = (string)($pdf['filename'] ?? 'RIDE.pdf');

            if ($content === '') {
                Response::error(
                    $this->fiscalDocumentUnavailableMessage('ride-pdf'),
                    404,
                    'BILLING_RIDE_PDF_NOT_AVAILABLE',
                    $this->fiscalDocumentUnavailableDetails('ride-pdf')
                );
                return;
            }

            Response::noStore();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_RIDE_PDF_INVALID_KEY');
        } catch (BillingApiException $e) {
            Response::error(
                $this->fiscalDocumentErrorMessage('ride-pdf', $e),
                $this->fiscalDocumentStatus($e),
                $this->fiscalDocumentErrorCode('ride-pdf', $e),
                $this->fiscalDocumentErrorDetails('ride-pdf', $e)
            );
        } catch (\Throwable $e) {
            Response::error('No se pudo preparar el RIDE PDF. Consulta el estado del comprobante o intenta nuevamente mas tarde.', 500, 'BILLING_RIDE_PDF_FAILED');
        }
    }

    public function cancelAndReissue(string $accessKey): void {
        $this->authenticate();

        try {
            $rawInput = file_get_contents('php://input');
            $data = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : [];
            if (!is_array($data)) {
                Response::error('JSON inválido', 400, 'BILLING_REISSUE_INVALID_JSON');
                return;
            }

            $reason = trim((string)($data['reason'] ?? ''));
            $confirmation = trim((string)($data['confirm_reissue'] ?? ''));
            if ($confirmation !== 'REEMITIR') {
                Response::error('Confirmación requerida para anular y reemitir. Esta acción puede generar un nuevo comprobante SRI.', 409, 'BILLING_REISSUE_CONFIRMATION_REQUIRED');
                return;
            }

            $ambiente = trim((string)($data['ambiente'] ?? ''));
            $billing = $this->billingGateway();
            $result = $billing->cancelAndReissueInvoice($accessKey, $reason, $ambiente !== '' ? $ambiente : null);
            $this->syncOrderBillingMetadata($result);

            Response::json($result);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_REISSUE_INVALID_KEY');
        } catch (\RuntimeException $e) {
            Response::error('No se pudo completar la reemision en el estado actual.', 409, 'BILLING_REISSUE_CONFLICT');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_REISSUE_FAILED');
        }
    }

    public function mailTest(string $accessKey): void {
        $this->authenticate();

        try {
            $data = $this->jsonBody(false);
            $ambiente = trim((string)($data['ambiente'] ?? $_GET['ambiente'] ?? ''));
            $billing = $this->billingGateway();
            Response::json($billing->sendMailTest($accessKey, $ambiente !== '' ? $ambiente : null));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_MAIL_TEST_INVALID_KEY');
        } catch (BillingApiException $e) {
            Response::error($e->getMessage(), $this->proxyStatus($e), 'BILLING_MAIL_TEST_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            $this->internalFailure($e, 'BILLING_MAIL_TEST_FAILED');
        }
    }

    private function internalFailure(\Throwable $exception, string $code, int $status = 500): void {
        error_log(json_encode([
            'event' => 'billing_internal_failure',
            'error_code' => $code,
            'error_type' => $exception::class,
            'exception_code' => (int)$exception->getCode(),
        ], JSON_UNESCAPED_SLASHES));
        Response::error('No se pudo completar la operacion de facturacion.', $status, $code);
    }

    private function buildInvoicePayloadFromProducts(array $data): array {
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $identification = $this->digitsOnly((string)($customer['identification'] ?? $data['customer_identification'] ?? ''));
        $customerName = trim((string)($customer['name'] ?? $data['customer_name'] ?? ''));
        if ($identification === '') {
            throw new \InvalidArgumentException('Identificacion del cliente requerida.');
        }
        if ($customerName === '') {
            throw new \InvalidArgumentException('Nombre del cliente requerido.');
        }

        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($rawItems) === 0) {
            throw new \InvalidArgumentException('Agrega al menos un producto para emitir la factura.');
        }

        $requested = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = trim((string)($item['product_id'] ?? $item['id'] ?? ''));
            if ($productId === '') {
                continue;
            }
            $quantity = max(1, (int)$this->number($item['quantity'] ?? 1, 1));
            $requested[$productId] = ($requested[$productId] ?? 0) + $quantity;
        }

        if (count($requested) === 0) {
            throw new \InvalidArgumentException('Las lineas de factura no tienen productos validos.');
        }

        $productCatalog = $this->productCatalog();
        $items = [];
        $lines = [];
        foreach ($requested as $productId => $quantity) {
            $product = $productCatalog->find($productId, [
                'includeUnpublished' => true,
                'includeProcurement' => true,
                'includeOutOfStock' => true,
            ]);
            if (!is_array($product)) {
                throw new \InvalidArgumentException('Producto no encontrado para facturar.');
            }

            $available = max(0, (int)($product['inventory']['available'] ?? $product['quantity'] ?? 0));
            if ($available < $quantity) {
                throw new \InvalidArgumentException(sprintf(
                    'Stock insuficiente para %s. Disponible: %d uds.',
                    (string)($product['name'] ?? 'producto'),
                    $available
                ));
            }

            $taxProfile = $this->resolveProductTaxProfile($product);
            $taxRate = $taxProfile['rate'];
            $priceGross = round(max(0, $this->number($product['price'] ?? 0)), 2);
            $lineGross = round($priceGross * $quantity, 2);
            $breakdown = $this->splitGrossAmountByTaxRate($lineGross, $taxRate);
            $unitNet = $quantity > 0 ? round($breakdown['net'] / $quantity, 6) : 0.0;
            $sku = trim((string)($this->attribute($product, 'sku') ?? $product['id']));

            $items[] = [
                'code' => $sku !== '' ? $sku : (string)$product['id'],
                'description' => (string)($product['name'] ?? 'Producto'),
                'quantity' => $quantity,
                'unit_price' => $this->billingDecimal($unitNet, 6),
                'discount' => '0.00',
                'line_subtotal_net' => $this->billingDecimal($breakdown['net'], 2),
                'tax_rate' => $this->billingDecimal($taxRate, 2),
                'tax_code' => EcuadorSriVatCatalog::TAX_CODE,
                'tax_percentage_code' => $taxProfile['percentage_code'],
                'tax_treatment' => $taxProfile['treatment'],
                'tax_amount' => $this->billingDecimal($breakdown['tax'], 2),
                'additional_detail' => 'Producto ' . (string)$product['id'] . ' · ' . $this->normalizeBillingProduct($product)['source'],
            ];

            $lines[] = [
                'product_id' => (string)$product['id'],
                'name' => (string)($product['name'] ?? 'Producto'),
                'quantity' => $quantity,
                'current_quantity' => $available,
            ];
        }

        $payment = $this->resolveSriPaymentMethod((string)($data['payment_method'] ?? 'cash'));
        $sourceReference = trim((string)($data['source_reference'] ?? ''));
        if ($sourceReference === '') {
            $sourceReference = 'BILL-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }

        $branch = is_array($data['branch'] ?? null) ? $data['branch'] : [];
        $branchId = (int)($data['branch_id'] ?? $data['branchId'] ?? $branch['id'] ?? 0);
        $branchCode = trim((string)($data['branch_code'] ?? $data['establishment_code'] ?? $branch['code'] ?? ''));
        $emissionPoint = trim((string)($data['emission_point'] ?? $data['emissionPoint'] ?? $branch['emission_point'] ?? ''));

        $payload = [
            'customer_identification' => $identification,
            'customer_name' => $customerName,
            'customer_address' => trim((string)($customer['address'] ?? $data['customer_address'] ?? 'Ecuador')) ?: 'Ecuador',
            'customer_email' => trim((string)($customer['email'] ?? $data['customer_email'] ?? '')),
            'items' => $items,
            'payment_method' => $payment['label'],
            'payment_method_code' => $payment['code'],
            'additional_info' => [
                'order_id' => $sourceReference,
                'source' => 'billing-sri',
                'tenant_slug' => TenantContext::slug(),
                'product_source' => $this->billingProductSource(),
                'payment_method' => $payment['label'],
                'payment_method_code' => $payment['code'],
            ],
        ];
        if ($branchId > 0) {
            $payload['branch_id'] = $branchId;
        } elseif ($branchCode !== '' || $emissionPoint !== '') {
            $payload['branch'] = [
                'code' => $branchCode,
                'emission_point' => $emissionPoint,
            ];
        }

        return [
            'source_reference' => $sourceReference,
            'lines' => $lines,
            'payload' => $payload,
        ];
    }

    private function consumeBillingInventory(array $lines, string $sourceReference): array {
        $productCatalog = $this->productCatalog();
        $movements = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = trim((string)($line['product_id'] ?? ''));
            $quantity = max(0, (int)($line['quantity'] ?? 0));
            $currentQuantity = max(0, (int)($line['current_quantity'] ?? 0));
            if ($productId === '' || $quantity <= 0) {
                continue;
            }

            $nextQuantity = max(0, $currentQuantity - $quantity);
            $updated = $productCatalog->update($productId, [
                'quantity' => $nextQuantity,
                'inventoryAction' => 'adjustment',
                'inventoryAdjustmentReason' => 'Venta facturada en SRI ' . $sourceReference,
            ]);

            $movements[] = [
                'product_id' => $productId,
                'name' => (string)($line['name'] ?? ''),
                'quantity' => $quantity,
                'previous_stock' => $currentQuantity,
                'new_stock' => is_array($updated) ? (int)($updated['quantity'] ?? $nextQuantity) : $nextQuantity,
            ];
        }

        return $movements;
    }

    private function normalizeBillingProduct(array $product): array {
        $taxProfile = $this->resolveProductTaxProfile($product);
        $taxRate = $taxProfile['rate'];
        $multiplier = 1 + ($taxRate / 100);
        $priceGross = round(max(0, $this->number($product['price'] ?? 0)), 2);
        $priceNet = $taxRate > 0 ? round($priceGross / $multiplier, 2) : $priceGross;
        $quantity = max(0, (int)($product['inventory']['available'] ?? $product['quantity'] ?? 0));
        $sku = trim((string)($this->attribute($product, 'sku') ?? ''));
        $images = $this->productImageUrls($product);

        return [
            'id' => (string)($product['id'] ?? ''),
            'sku' => $sku,
            'code_aux' => (string)($this->attribute($product, 'auxCode') ?? ''),
            'name' => (string)($product['name'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'category' => (string)($product['category'] ?? ''),
            'product_type' => (string)($product['productType'] ?? $product['product_type'] ?? ''),
            'brand' => (string)($product['brand'] ?? ''),
            'unit_measure' => (string)($this->attribute($product, 'unitMeasure') ?? 'unidad'),
            'image_url' => $images[0] ?? '',
            'images' => $images,
            'price_gross' => $priceGross,
            'price_net' => $priceNet,
            'tax_rate' => round($taxRate, 2),
            'tax_exempt' => $taxProfile['treatment'] === EcuadorSriVatCatalog::TREATMENT_EXEMPT,
            'tax_treatment' => $taxProfile['treatment'],
            'tax_percentage_code' => $taxProfile['percentage_code'],
            'quantity' => $quantity,
            'inventory_status' => (string)($product['inventoryStatus'] ?? $product['inventory']['status'] ?? ''),
            'active' => $this->boolValue($this->attribute($product, 'billingActive') ?? true),
            'source' => $this->boolValue($this->attribute($product, 'billingOnly') ?? false)
                ? 'billing'
                : $this->billingProductSource(),
        ];
    }

    private function productImagePayload(array $data, string $name): array {
        $raw = $data['image'] ?? $data['image_url'] ?? $data['images'] ?? [];
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            return [[
                'url' => $raw,
                'altText' => $name,
                'kind' => 'gallery',
            ]];
        }

        return is_array($raw) ? $raw : [];
    }

    private function productImageUrls(array $product): array {
        $urls = [];
        foreach (['thumbImage', 'images'] as $key) {
            $items = $product[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $url = is_string($item) ? trim($item) : '';
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        $meta = $product['imageMeta'] ?? [];
        if (is_array($meta)) {
            foreach ($meta as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $url = trim((string)($item['url'] ?? ''));
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    private function productMatchesSearch(array $product, string $search): bool {
        if ($search === '') {
            return true;
        }

        $needle = $this->searchToken($search);
        $haystack = $this->searchToken(implode(' ', [
            $product['name'] ?? '',
            $product['category'] ?? '',
            $product['brand'] ?? '',
            $this->attribute($product, 'sku') ?? '',
            $this->attribute($product, 'supplier') ?? '',
        ]));

        return $needle === '' || str_contains($haystack, $needle);
    }

    private function attribute(array $product, string $key): mixed {
        $attributes = $product['attributes'] ?? [];
        if (is_string($attributes)) {
            $decoded = json_decode($attributes, true);
            $attributes = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($attributes)) {
            return null;
        }

        return $attributes[$key] ?? null;
    }

    private function tenantHasEcommerce(): bool {
        $tenant = TenantContext::get() ?? [];
        $modules = is_array($tenant['enabled_modules'] ?? null) ? $tenant['enabled_modules'] : [];
        return in_array('ecommerce', $modules, true);
    }

    private function billingProductSource(): string {
        return $this->tenantHasEcommerce() ? 'ecommerce' : 'billing';
    }

    private function number(mixed $value, float $default = 0): float {
        if (is_string($value)) {
            $normalized = trim(str_replace(['$', ' '], '', $value));
            if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } elseif (str_contains($normalized, ',')) {
                $normalized = str_replace(',', '.', $normalized);
            }
            return is_numeric($normalized) ? (float)$normalized : $default;
        }

        return is_numeric($value) ? (float)$value : $default;
    }

    private function boolValue(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'si', 'on'], true);
    }

    private function digitsOnly(string $value): string {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function billingDecimal($value, int $decimals): string {
        $number = is_numeric($value) ? (float)$value : 0.0;
        if (abs($number) < 0.0000005) {
            $number = 0.0;
        }

        return number_format($number, $decimals, '.', '');
    }

    private function splitGrossAmountByTaxRate(float $grossAmount, float $taxRate): array {
        $grossAmount = round(max(0, $grossAmount), 2);
        $taxRate = max(0, $taxRate);
        if ($grossAmount <= 0 || $taxRate <= 0) {
            return [
                'gross' => $grossAmount,
                'net' => $grossAmount,
                'tax' => 0.0,
            ];
        }

        $divisor = 1 + ($taxRate / 100);
        $net = round($grossAmount / $divisor, 2);
        $tax = round($grossAmount - $net, 2);

        return [
            'gross' => $grossAmount,
            'net' => $net,
            'tax' => $tax,
        ];
    }

    /** @return array{rate: float, treatment: string, percentage_code: string} */
    private function resolveProductTaxProfile(array $product): array {
        $tax = is_array($product['tax'] ?? null) ? $product['tax'] : [];
        $rawRate = $tax['rate'] ?? $this->attribute($product, 'taxRate');
        if ($rawRate === null || $rawRate === '') {
            throw new \InvalidArgumentException('Product tax profile does not contain an explicit IVA rate.');
        }
        $rate = EcuadorSriVatCatalog::assertSupportedRate($rawRate);
        $treatment = EcuadorSriVatCatalog::normalizeTreatment(
            $tax['treatment'] ?? $this->attribute($product, 'taxTreatment') ?? null
        );
        if ($treatment === null && $this->boolValue($this->attribute($product, 'taxExempt') ?? false)) {
            $treatment = EcuadorSriVatCatalog::TREATMENT_EXEMPT;
        }
        $percentageCode = trim((string)($tax['percentage_code'] ?? $tax['percentageCode'] ?? ''));
        $treatment ??= EcuadorSriVatCatalog::inferTreatment($rate, $percentageCode);
        $percentageCode = $percentageCode === ''
            ? EcuadorSriVatCatalog::percentageCode($rate, $treatment)
            : EcuadorSriVatCatalog::assertCodeMatches($rate, $treatment, $percentageCode);

        return [
            'rate' => $rate,
            'treatment' => $treatment,
            'percentage_code' => $percentageCode,
        ];
    }

    private function resolveSriPaymentMethod(string $method): array {
        $value = strtolower(trim($method));
        if (in_array($value, ['credit', 'card', 'credit_card', 'tarjeta'], true)) {
            return [
                'code' => '19',
                'label' => 'Tarjeta de credito',
            ];
        }
        if (in_array($value, ['transfer', 'bank_transfer', 'transferencia'], true)) {
            return [
                'code' => '20',
                'label' => 'Otros con utilizacion del sistema financiero',
            ];
        }
        if (in_array($value, ['cash', 'cod', 'efectivo'], true)) {
            return [
                'code' => '01',
                'label' => 'Sin utilizacion del sistema financiero',
            ];
        }
        if (preg_match('/^\d{2}$/', $method) === 1) {
            return [
                'code' => $method,
                'label' => $method,
            ];
        }

        return [
            'code' => '20',
            'label' => trim($method) !== '' ? trim($method) : 'Otros con utilizacion del sistema financiero',
        ];
    }

    private function searchToken(string $value): string {
        $ascii = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);

        return preg_replace('/[^a-z0-9]+/i', '', strtolower($ascii)) ?? '';
    }

    private function jsonBody(bool $required = true): array {
        $rawInput = file_get_contents('php://input');
        if (!is_string($rawInput) || trim($rawInput) === '') {
            if (!$required) {
                return [];
            }
            throw new \InvalidArgumentException('JSON requerido');
        }

        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON inválido');
        }

        return $data;
    }

    private function queryAmbiente(): ?string {
        $ambiente = trim((string)($_GET['ambiente'] ?? ''));
        return $ambiente !== '' ? $ambiente : null;
    }

    private function proxyStatus(BillingApiException $exception): int {
        $status = $exception->httpStatusCode();
        if ($status >= 400 && $status < 500) {
            return $status;
        }

        return 502;
    }

    private function fiscalDocumentStatus(BillingApiException $exception): int {
        $status = $exception->httpStatusCode();
        if ($status >= 400 && $status < 500) {
            return $status;
        }

        return 502;
    }

    private function fiscalDocumentErrorCode(string $documentType, BillingApiException $exception): string {
        $prefix = $documentType === 'xml' ? 'BILLING_XML' : 'BILLING_RIDE_PDF';
        $status = $exception->httpStatusCode();
        if ($status === 400) {
            return $prefix . '_INVALID_KEY';
        }
        if ($status === 404 || $status === 409) {
            return $prefix . '_NOT_AVAILABLE';
        }

        return $prefix . '_FAILED';
    }

    private function fiscalDocumentErrorMessage(string $documentType, BillingApiException $exception): string {
        $status = $exception->httpStatusCode();
        if ($status === 400) {
            return 'La clave de acceso del comprobante no es valida.';
        }
        if ($status === 404 || $status === 409) {
            return $this->fiscalDocumentUnavailableMessage($documentType);
        }

        if ($documentType === 'xml') {
            return 'No se pudo preparar el XML autorizado. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
        }

        return 'No se pudo preparar el RIDE PDF. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
    }

    private function fiscalDocumentUnavailableMessage(string $documentType): string {
        if ($documentType === 'xml') {
            return 'El XML autorizado aun no esta disponible para descarga. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
        }

        return 'El RIDE PDF aun no esta disponible para este comprobante. Consulta el estado o intenta nuevamente mas tarde.';
    }

    private function fiscalDocumentErrorDetails(string $documentType, BillingApiException $exception): array {
        $status = $exception->httpStatusCode();
        if ($status === 404 || $status === 409) {
            return $this->fiscalDocumentUnavailableDetails($documentType);
        }

        return [
            'document' => $documentType,
            'status' => 'failed',
            'action' => 'check_status',
        ];
    }

    private function fiscalDocumentUnavailableDetails(string $documentType): array {
        return [
            'document' => $documentType,
            'status' => 'pending',
            'action' => 'check_status',
            'retry_after_seconds' => 3600,
        ];
    }

    private function syncOrderBillingMetadata(array $result): void {
        $oldInvoice = is_array($result['old_invoice'] ?? null) ? $result['old_invoice'] : [];
        $newInvoice = is_array($result['new_invoice'] ?? null) ? $result['new_invoice'] : [];
        $orderId = trim((string)($newInvoice['source_reference'] ?? $oldInvoice['source_reference'] ?? ''));
        if ($orderId === '') {
            return;
        }

        $orderAccounting = $this->orderAccounting();
        $accountingDates = $orderAccounting->accountingDates([$orderId]);
        $accountingDate = is_array($accountingDates[$orderId] ?? null) ? $accountingDates[$orderId] : [];

        $metadata = [
            'provider' => 'billing-sri',
            'status' => 'reissued',
            'invoice_status' => $newInvoice['sri_status'] ?? null,
            'access_key' => $newInvoice['access_key'] ?? null,
            'sequential' => $this->formatSequential($newInvoice),
            'issue_date' => $newInvoice['issue_date'] ?? null,
            'total' => $newInvoice['total'] ?? null,
            'authorization_number' => $newInvoice['authorization_number'] ?? null,
            'authorization_date' => $newInvoice['authorization_date'] ?? null,
            'reissued_at' => date('c'),
            'reissued_from_access_key' => $oldInvoice['access_key'] ?? null,
            'last_attempt_at' => date('c'),
            'last_error' => null,
        ];

        foreach (['accounting_date', 'order_created_at', 'financial_period_key'] as $field) {
            if (!empty($accountingDate[$field])) {
                $metadata[$field] = $accountingDate[$field];
            }
        }
        foreach ([
            'operational_error',
            'operational_error_code',
            'operational_error_label',
            'operational_error_reason',
            'operational_error_marked_at',
            'operational_error_actor',
        ] as $field) {
            if (array_key_exists($field, $newInvoice)) {
                $metadata[$field] = $newInvoice[$field];
            }
        }

        $orderAccounting->updateBillingMetadata($orderId, $metadata);
    }

    private function enrichRidesWithAccountingDates(array $rides): array {
        $orderIds = [];
        foreach ($rides as $ride) {
            if (!is_array($ride)) {
                continue;
            }

            $sourceReference = trim((string)($ride['source_reference'] ?? ''));
            if ($sourceReference !== '') {
                $orderIds[] = $sourceReference;
            }
        }

        if (count($orderIds) === 0) {
            return $rides;
        }

        $datesByOrderId = $this->orderAccounting()->accountingDates($orderIds);
        if (count($datesByOrderId) === 0) {
            return $rides;
        }

        foreach ($rides as $index => $ride) {
            if (!is_array($ride)) {
                continue;
            }

            $sourceReference = trim((string)($ride['source_reference'] ?? ''));
            $dates = is_array($datesByOrderId[$sourceReference] ?? null) ? $datesByOrderId[$sourceReference] : null;
            if ($dates === null) {
                continue;
            }

            $rides[$index]['accounting_date'] = $dates['accounting_date'] ?? null;
            $rides[$index]['order_created_at'] = $dates['order_created_at'] ?? null;
            $rides[$index]['financial_period_key'] = $dates['financial_period_key'] ?? null;
        }

        return $rides;
    }

    private function formatSequential(array $invoice): ?string {
        $parts = [
            $invoice['establishment_code'] ?? null,
            $invoice['emission_point'] ?? null,
            $invoice['sequential'] ?? null,
        ];
        $parts = array_values(array_filter($parts, static fn($value) => is_string($value) && trim($value) !== ''));
        return count($parts) === 3 ? implode('-', $parts) : ($invoice['sequential'] ?? null);
    }
}
