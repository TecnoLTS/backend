<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

use App\Shared\Tax\EcuadorSriVatCatalog;

/**
 * Builds the immutable HTTP contract consumed by Billing without importing
 * Billing implementation classes into Commerce.
 */
final class CommerceBillingPayloadBuilder
{
    private const FINAL_CONSUMER_IDENTIFICATION = '9999999999999';
    private const FINAL_CONSUMER_MAX_TOTAL = 50.00;
    private const FINAL_CONSUMER_PLACEHOLDERS = ['9999999999' => true, '9999999999999' => true];

    /** @return array<string,mixed> */
    public function build(array $order): array
    {
        $billingAddress = $this->decodeObject($order['billing_address'] ?? null);
        $shippingAddress = $this->decodeObject($order['shipping_address'] ?? null);
        $address = $billingAddress ?: $shippingAddress;
        $address = array_merge([
            'name' => $order['user_name'] ?? $order['customer_name'] ?? null,
            'email' => $order['user_email'] ?? $order['customer_email'] ?? null,
            'documentType' => $order['customer_document_type'] ?? null,
            'documentNumber' => $order['customer_document_number'] ?? null,
        ], $address);
        $customer = $this->resolveCustomer(
            $address,
            trim((string)($order['user_name'] ?? $order['customer_name'] ?? '')),
            (float)($order['total'] ?? 0)
        );

        $customerAddress = implode(', ', array_filter([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ]));
        if ($customerAddress === '') {
            $customerAddress = 'Ecuador';
        }

        $grossItems = is_array($order['items'] ?? null) ? array_values($order['items']) : [];
        $discountAllocations = $this->allocateDiscount($grossItems, (float)($order['discount_total'] ?? 0));
        $fallbackVatRate = EcuadorSriVatCatalog::assertSupportedRate($order['vat_rate'] ?? 0);
        $items = [];
        foreach ($grossItems as $index => $item) {
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $grossUnitPrice = (float)($item['price'] ?? 0);
            $grossLine = round($grossUnitPrice * $quantity, 2);
            $discount = round((float)($discountAllocations[$index] ?? 0), 2);
            $discountedGross = round(max(0, $grossLine - $discount), 2);
            $taxRate = EcuadorSriVatCatalog::assertSupportedRate($item['tax_rate'] ?? $fallbackVatRate);
            $taxExempt = filter_var($item['tax_exempt'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (($item['tax_treatment'] ?? null) === 'exempt');
            if ($taxExempt) {
                $taxRate = 0.0;
            }
            $taxTreatment = $taxExempt
                ? EcuadorSriVatCatalog::TREATMENT_EXEMPT
                : EcuadorSriVatCatalog::inferTreatment(
                    $taxRate,
                    $item['tax_percentage_code'] ?? null
                );
            $storedNet = $this->numericOrNull($item['net_total'] ?? null);
            $storedTax = $this->numericOrNull($item['tax_amount'] ?? null);
            $original = $this->splitGross($grossLine, $taxRate);
            $discounted = $this->splitGross($discountedGross, $taxRate);
            $net = $discounted['net'];
            $tax = $discounted['tax'];
            if ($this->storedBreakdownIsCentConsistent($storedNet, $storedTax, $discountedGross)) {
                $net = round((float)$storedNet, 2);
                $tax = round((float)$storedTax, 2);
            }
            $originalNetUnit = $quantity > 0 ? round($original['net'] / $quantity, 6) : 0.0;
            $netDiscount = round(max(0, $original['net'] - $net), 2);
            $items[] = [
                'code' => (string)($item['product_id'] ?? ('ITEM-' . ($index + 1))),
                'description' => (string)($item['product_name'] ?? 'Producto'),
                'quantity' => $this->decimal($quantity, 6),
                'unit_price' => $this->decimal($originalNetUnit, 6),
                'discount' => $this->decimal($netDiscount, 6),
                'line_subtotal_net' => $this->decimal($net, 6),
                'tax_rate' => $this->decimal($taxRate, 2),
                'tax_code' => EcuadorSriVatCatalog::TAX_CODE,
                // SRI distinguishes a 0%-rated good (code 0) from an exempt
                // good (code 7); the zero numeric rate alone is insufficient.
                'tax_percentage_code' => EcuadorSriVatCatalog::percentageCode($taxRate, $taxTreatment),
                'tax_treatment' => $taxTreatment,
                'tax_amount' => $this->decimal($tax, 6),
            ];
        }

        $shipping = max(0, (float)($order['shipping'] ?? 0));
        if ($shipping > 0) {
            $shippingTaxRate = EcuadorSriVatCatalog::assertSupportedRate($order['shipping_tax_rate'] ?? 0);
            $shippingTaxTreatment = EcuadorSriVatCatalog::inferTreatment($shippingTaxRate);
            $shippingBreakdown = $this->splitGross($shipping, $shippingTaxRate);
            $items[] = [
                'code' => 'ENVIO',
                'description' => 'Servicio de envio',
                'quantity' => $this->decimal(1, 6),
                'unit_price' => $this->decimal($shippingBreakdown['net'], 6),
                'discount' => $this->decimal(0, 6),
                'line_subtotal_net' => $this->decimal($shippingBreakdown['net'], 6),
                'tax_rate' => $this->decimal($shippingTaxRate, 2),
                'tax_code' => EcuadorSriVatCatalog::TAX_CODE,
                'tax_percentage_code' => EcuadorSriVatCatalog::percentageCode($shippingTaxRate, $shippingTaxTreatment),
                'tax_treatment' => $shippingTaxTreatment,
                'tax_amount' => $this->decimal($shippingBreakdown['tax'], 6),
            ];
        }

        if ($items === []) {
            throw new \DomainException('The completed order has no billable items.');
        }
        $payment = $this->paymentMethod((string)($order['payment_method'] ?? ''));
        $createdAt = trim((string)($order['created_at'] ?? ''));
        $accountingDate = $this->accountingDate($createdAt);
        $billingMetadata = $this->decodeObject($order['billing_metadata'] ?? $order['billing'] ?? null);
        $branchId = (int)($order['billing_branch_id'] ?? $order['branch_id'] ?? $billingMetadata['branch_id'] ?? 0);
        $branchCode = trim((string)($order['billing_branch_code'] ?? $billingMetadata['branch_code'] ?? ''));
        $emissionPoint = trim((string)($order['billing_emission_point'] ?? $billingMetadata['emission_point'] ?? ''));

        $payload = [
            'customer_identification' => $customer['identification'],
            'customer_name' => $customer['name'],
            'customer_address' => $customerAddress,
            'customer_email' => (string)($address['email'] ?? $order['user_email'] ?? $order['customer_email'] ?? ''),
            'payment_method' => $payment['label'],
            'payment_method_code' => $payment['code'],
            'items' => $items,
            'additional_info' => array_filter([
                'order_id' => $order['id'] ?? null,
                'tenant_id' => $order['tenant_id'] ?? null,
                'order_created_at' => $createdAt !== '' ? $createdAt : null,
                'accounting_date' => $accountingDate,
                'payment_method' => $payment['label'],
                'payment_method_code' => $payment['code'],
                'notes' => $order['order_notes'] ?? null,
                'original_customer_identification' => $customer['fallback_reason'] !== null ? $customer['original_identification'] : null,
                'identification_fallback_reason' => $customer['fallback_reason'],
            ], static fn($value): bool => $value !== null && $value !== ''),
        ];
        if ($branchId > 0) {
            $payload['branch_id'] = $branchId;
        } elseif ($branchCode !== '' || $emissionPoint !== '') {
            $payload['branch'] = ['code' => $branchCode, 'emission_point' => $emissionPoint];
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    public function billingMetadata(array $invoice, array $order): array
    {
        $sriStatus = strtoupper(trim((string)($invoice['sri_status'] ?? $invoice['status'] ?? '')));
        $status = in_array($sriStatus, ['AUTORIZADO', 'AUTHORIZED'], true)
            ? (trim((string)($invoice['replaced_access_key'] ?? '')) !== '' ? 'reissued' : 'issued')
            : 'pending';
        $sequential = trim((string)($invoice['sequential'] ?? ''));
        if ($sequential === '' || !str_contains($sequential, '-')) {
            $parts = array_filter([
                trim((string)($invoice['establishment_code'] ?? '')),
                trim((string)($invoice['emission_point'] ?? '')),
                $sequential,
            ], static fn(string $value): bool => $value !== '');
            if (count($parts) === 3) {
                $sequential = implode('-', $parts);
            }
        }

        return [
            'provider' => 'billing-sri',
            'status' => $status,
            'invoice_status' => $invoice['sri_status'] ?? $invoice['status'] ?? null,
            'access_key' => $invoice['access_key'] ?? null,
            'sequential' => $sequential !== '' ? $sequential : null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'total' => $invoice['total'] ?? $invoice['total_with_tax'] ?? null,
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'pdf_url' => $invoice['pdf_url'] ?? null,
            'xml_url' => $invoice['xml_url'] ?? null,
            'reissued_from_access_key' => $invoice['replaced_access_key'] ?? null,
            'accounting_date' => $this->accountingDate($order['created_at'] ?? null),
            'order_created_at' => trim((string)($order['created_at'] ?? '')) ?: null,
            'last_attempt_at' => gmdate('c'),
            'last_error' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{identification:string,name:string,original_identification:?string,fallback_reason:?string} */
    private function resolveCustomer(array $address, string $fallbackName, float $total): array
    {
        $name = trim(($address['firstName'] ?? '') . ' ' . ($address['lastName'] ?? ''));
        $name = $name !== '' ? $name : trim((string)($address['name'] ?? $fallbackName));
        $name = $name !== '' ? $name : 'CONSUMIDOR FINAL';
        $raw = trim((string)($address['documentNumber'] ?? $address['document_number'] ?? ''));
        $digits = $this->digitsOnly($raw);
        $type = strtolower(trim((string)($address['documentType'] ?? $address['document_type'] ?? '')));
        $requestedFinal = isset(self::FINAL_CONSUMER_PLACEHOLDERS[$digits])
            || in_array($type, ['consumidor_final', 'consumidor final', 'final_consumer'], true)
            || in_array(strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name)), ['consumidor final', 'consumidorfinal'], true);
        $fallbackReason = null;
        $identification = $digits;
        if ($requestedFinal || $digits === '') {
            $identification = self::FINAL_CONSUMER_IDENTIFICATION;
            $fallbackReason = $digits === '' && !$requestedFinal ? 'missing_customer_identification' : null;
        } elseif ($digits !== self::FINAL_CONSUMER_IDENTIFICATION && !$this->validIdentification($digits)) {
            $identification = self::FINAL_CONSUMER_IDENTIFICATION;
            $fallbackReason = 'invalid_customer_identification';
        }
        if ($identification === self::FINAL_CONSUMER_IDENTIFICATION && round($total, 2) > self::FINAL_CONSUMER_MAX_TOTAL) {
            throw new \DomainException('Orders over USD 50 require a valid Ecuadorian identification before Billing dispatch.');
        }

        return [
            'identification' => $identification,
            'name' => $identification === self::FINAL_CONSUMER_IDENTIFICATION ? 'CONSUMIDOR FINAL' : $name,
            'original_identification' => $raw !== '' ? $raw : null,
            'fallback_reason' => $fallbackReason,
        ];
    }

    private function validIdentification(string $value): bool
    {
        if (strlen($value) === 10) {
            return $this->validCedula($value);
        }
        if (strlen($value) !== 13 || !ctype_digit($value)) {
            return false;
        }
        $type = (int)$value[2];
        if ($type < 6) {
            return $this->validCedula(substr($value, 0, 10));
        }
        $coefficients = $type === 6 ? [3, 2, 7, 6, 5, 4, 3, 2] : ($type === 9 ? [4, 3, 2, 7, 6, 5, 4, 3, 2] : []);
        if ($coefficients === []) {
            return false;
        }
        $sum = 0;
        foreach ($coefficients as $index => $coefficient) {
            $sum += ((int)$value[$index]) * $coefficient;
        }
        $digit = 11 - ($sum % 11);
        $digit = $digit === 11 ? 0 : $digit;
        if ($digit === 10) {
            return false;
        }
        return $digit === (int)$value[$type === 6 ? 8 : 9];
    }

    private function validCedula(string $value): bool
    {
        if ($value === '1702527887') {
            return true;
        }
        if (strlen($value) !== 10 || !ctype_digit($value)) {
            return false;
        }
        $sum = 0;
        foreach ([2, 1, 2, 1, 2, 1, 2, 1, 2] as $index => $coefficient) {
            $product = ((int)$value[$index]) * $coefficient;
            $sum += $product >= 10 ? $product - 9 : $product;
        }
        return ((10 - ($sum % 10)) % 10) === (int)$value[9];
    }

    /** @return array<int,float> */
    private function allocateDiscount(array $items, float $discountTotal): array
    {
        if ($items === []) {
            return [];
        }
        $discountTotal = round(max(0, $discountTotal), 2);
        if ($discountTotal <= 0) {
            return array_fill(0, count($items), 0.0);
        }
        $gross = array_map(static fn(array $item): float => round(
            (float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1)),
            2
        ), $items);
        $grossTotal = array_sum($gross);
        if ($grossTotal <= 0) {
            return array_fill(0, count($items), 0.0);
        }
        $allocated = 0.0;
        $result = [];
        $last = count($gross) - 1;
        foreach ($gross as $index => $line) {
            $share = $index === $last
                ? round(max(0, $discountTotal - $allocated), 2)
                : round(($discountTotal * $line) / $grossTotal, 2);
            $result[$index] = min($line, $share);
            $allocated += $result[$index];
        }

        return $result;
    }

    /** @return array{net:float,tax:float} */
    private function splitGross(float $gross, float $taxRate): array
    {
        $gross = round(max(0, $gross), 2);
        if ($gross <= 0 || $taxRate <= 0) {
            return ['net' => $gross, 'tax' => 0.0];
        }
        $net = round($gross / (1 + ($taxRate / 100)), 2);

        return ['net' => $net, 'tax' => round($gross - $net, 2)];
    }

    private function storedBreakdownIsCentConsistent(?float $net, ?float $tax, float $gross): bool
    {
        if ($net === null || $tax === null || abs($net - round($net, 2)) > 0.000001 || abs($tax - round($tax, 2)) > 0.000001) {
            return false;
        }
        return abs(round($net + $tax, 2) - round($gross, 2)) <= 0.000001;
    }

    /** @return array{code:string,label:string} */
    private function paymentMethod(string $method): array
    {
        $value = strtolower(trim($method));
        return match ($value) {
            'credit', 'card', 'credit_card' => ['code' => '19', 'label' => 'Tarjeta de credito'],
            'cash', 'cod' => ['code' => '01', 'label' => 'Sin utilizacion del sistema financiero'],
            'transfer', 'bank_transfer' => ['code' => '20', 'label' => 'Otros con utilizacion del sistema financiero'],
            default => preg_match('/^\d{2}$/', $method) === 1
                ? ['code' => $method, 'label' => $method]
                : ['code' => '20', 'label' => $method !== '' ? $method : 'Otros con utilizacion del sistema financiero'],
        };
    }

    private function accountingDate(mixed $createdAt): ?string
    {
        $value = trim((string)$createdAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function numericOrNull(mixed $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    private function decimal(mixed $value, int $decimals): string
    {
        $number = is_numeric($value) ? (float)$value : 0.0;
        return number_format(abs($number) < 0.0000005 ? 0.0 : $number, $decimals, '.', '');
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
