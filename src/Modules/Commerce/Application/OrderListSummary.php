<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

/**
 * Contrato deliberadamente pequeno para listados de pedidos.
 *
 * El detalle (direcciones, notas, pago, factura y lineas) pertenece a
 * GET /api/orders/{id}; nunca debe filtrarse accidentalmente en una pagina.
 */
final class OrderListSummary
{
    public const CONTRACT = 'order-summary-v1';
    public const MAX_PAGE_SIZE = 100;
    public const MAX_RESPONSE_BYTES = 262144;

    private const MAX_IDENTIFIER_BYTES = 128;
    private const MAX_STATUS_BYTES = 32;
    private const MAX_TIMESTAMP_BYTES = 40;
    private const MAX_DELIVERY_METHOD_BYTES = 32;
    private const MAX_PAYMENT_METHOD_BYTES = 64;
    // SQL limita a 80 caracteres; 320 cubre el peor caso UTF-8 de 4 bytes.
    private const MAX_USER_NAME_BYTES = 320;
    private const MAX_USER_EMAIL_BYTES = 254;
    private const MAX_PHONE_BYTES = 40;
    private const MAX_DOCUMENT_TYPE_BYTES = 32;
    private const MAX_DOCUMENT_NUMBER_BYTES = 64;
    private const MAX_COMPANY_BYTES = 320;
    private const MAX_SALES_CHANNEL_BYTES = 32;
    private const MAX_DISCOUNT_CODE_BYTES = 80;
    private const MAX_INVOICE_NUMBER_BYTES = 80;

    /** @var list<string> */
    public const FIELDS = [
        'id',
        'user_id',
        'total',
        'status',
        'created_at',
        'delivery_method',
        'payment_method',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_document_type',
        'customer_document_number',
        'customer_company',
        'sales_channel',
        'items_count',
        'units_count',
        'mixed_vat_rates',
        'items_subtotal',
        'vat_subtotal',
        'vat_rate',
        'vat_amount',
        'shipping',
        'shipping_base',
        'shipping_tax_rate',
        'shipping_tax_amount',
        'discount_code',
        'discount_total',
        'invoice_number',
        'invoice_created_at',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function fromDatabaseRow(array $row): array
    {
        $id = self::requiredBoundedString($row['id'] ?? null, 'id', self::MAX_IDENTIFIER_BYTES);
        $createdAt = self::requiredBoundedString(
            $row['created_at'] ?? null,
            'created_at',
            self::MAX_TIMESTAMP_BYTES
        );

        return [
            'id' => $id,
            'user_id' => self::nullableBoundedString(
                $row['user_id'] ?? null,
                'user_id',
                self::MAX_IDENTIFIER_BYTES
            ),
            'total' => round((float)($row['total'] ?? 0), 2),
            'status' => self::requiredBoundedString(
                $row['status'] ?? null,
                'status',
                self::MAX_STATUS_BYTES
            ),
            'created_at' => $createdAt,
            'delivery_method' => self::nullableBoundedString(
                $row['delivery_method'] ?? null,
                'delivery_method',
                self::MAX_DELIVERY_METHOD_BYTES
            ),
            'payment_method' => self::nullableBoundedString(
                $row['payment_method'] ?? null,
                'payment_method',
                self::MAX_PAYMENT_METHOD_BYTES
            ),
            'customer_name' => self::nullableBoundedString(
                $row['customer_name'] ?? null,
                'customer_name',
                self::MAX_USER_NAME_BYTES
            ),
            'customer_email' => self::nullableBoundedString(
                $row['customer_email'] ?? null,
                'customer_email',
                self::MAX_USER_EMAIL_BYTES
            ),
            'customer_phone' => self::nullableBoundedString(
                $row['customer_phone'] ?? null,
                'customer_phone',
                self::MAX_PHONE_BYTES
            ),
            'customer_document_type' => self::nullableBoundedString(
                $row['customer_document_type'] ?? null,
                'customer_document_type',
                self::MAX_DOCUMENT_TYPE_BYTES
            ),
            'customer_document_number' => self::nullableBoundedString(
                $row['customer_document_number'] ?? null,
                'customer_document_number',
                self::MAX_DOCUMENT_NUMBER_BYTES
            ),
            'customer_company' => self::nullableBoundedString(
                $row['customer_company'] ?? null,
                'customer_company',
                self::MAX_COMPANY_BYTES
            ),
            'sales_channel' => self::nullableBoundedString(
                $row['sales_channel'] ?? null,
                'sales_channel',
                self::MAX_SALES_CHANNEL_BYTES
            ),
            'items_count' => self::nonNegativeInteger($row['items_count'] ?? 0, 'items_count'),
            'units_count' => self::nonNegativeInteger($row['units_count'] ?? 0, 'units_count'),
            'mixed_vat_rates' => self::booleanValue($row['mixed_vat_rates'] ?? false),
            'items_subtotal' => self::money($row['items_subtotal'] ?? 0),
            'vat_subtotal' => self::money($row['vat_subtotal'] ?? 0),
            'vat_rate' => round((float)($row['vat_rate'] ?? 0), 2),
            'vat_amount' => self::money($row['vat_amount'] ?? 0),
            'shipping' => self::money($row['shipping'] ?? 0),
            'shipping_base' => self::money($row['shipping_base'] ?? 0),
            'shipping_tax_rate' => round((float)($row['shipping_tax_rate'] ?? 0), 2),
            'shipping_tax_amount' => self::money($row['shipping_tax_amount'] ?? 0),
            'discount_code' => self::nullableBoundedString(
                $row['discount_code'] ?? null,
                'discount_code',
                self::MAX_DISCOUNT_CODE_BYTES
            ),
            'discount_total' => self::money($row['discount_total'] ?? 0),
            'invoice_number' => self::nullableBoundedString(
                $row['invoice_number'] ?? null,
                'invoice_number',
                self::MAX_INVOICE_NUMBER_BYTES
            ),
            'invoice_created_at' => self::nullableBoundedString(
                $row['invoice_created_at'] ?? null,
                'invoice_created_at',
                self::MAX_TIMESTAMP_BYTES
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function projectRows(array $rows): array
    {
        if (count($rows) > self::MAX_PAGE_SIZE) {
            throw new \OverflowException(sprintf(
                'Una pagina de pedidos no puede superar %d registros.',
                self::MAX_PAGE_SIZE
            ));
        }

        return array_map(self::fromDatabaseRow(...), $rows);
    }

    /**
     * Comprueba el cuerpo HTTP real, incluyendo el envelope y su metadata.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $meta
     */
    public static function assertResponseBudget(array $items, array $meta): int
    {
        $encoded = json_encode([
            'ok' => true,
            'data' => $items,
            'meta' => $meta,
        ], JSON_THROW_ON_ERROR);
        $bytes = strlen($encoded);
        if ($bytes > self::MAX_RESPONSE_BYTES) {
            throw new \OverflowException(sprintf(
                'La pagina resumen de pedidos excede el presupuesto de %d bytes.',
                self::MAX_RESPONSE_BYTES
            ));
        }

        return $bytes;
    }

    private static function requiredBoundedString(mixed $value, string $field, int $maxBytes): string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            throw new \UnexpectedValueException("El resumen de pedido no contiene {$field}.");
        }
        self::assertStringBudget($normalized, $field, $maxBytes);

        return $normalized;
    }

    private static function nullableBoundedString(mixed $value, string $field, int $maxBytes): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }
        self::assertStringBudget($normalized, $field, $maxBytes);

        return $normalized;
    }

    private static function assertStringBudget(string $value, string $field, int $maxBytes): void
    {
        if (strlen($value) > $maxBytes) {
            throw new \OverflowException(sprintf(
                'El campo %s del resumen de pedido excede %d bytes.',
                $field,
                $maxBytes
            ));
        }
    }

    private static function nonNegativeInteger(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException("El campo {$field} del resumen de pedido no es numerico.");
        }
        $normalized = (int)$value;
        if ($normalized < 0) {
            throw new \UnexpectedValueException("El campo {$field} del resumen de pedido es negativo.");
        }

        return $normalized;
    }

    private static function money(mixed $value): float
    {
        return round((float)$value, 2);
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
