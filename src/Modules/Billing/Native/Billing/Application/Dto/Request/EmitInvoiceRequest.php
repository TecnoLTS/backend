<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Dto\Request;

class EmitInvoiceRequest
{
    public function __construct(
        public readonly string $customerIdentification,
        public readonly string $customerName,
        public readonly string $customerAddress,
        public readonly string $customerEmail,
        public readonly array $items,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentMethodCode = null,
        public readonly array $additionalInfo = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            customerIdentification: $data['customer_identification'] ?? '',
            customerName: $data['customer_name'] ?? '',
            customerAddress: $data['customer_address'] ?? 'N/A',
            customerEmail: $data['customer_email'] ?? '',
            items: $data['items'] ?? [],
            paymentMethod: self::extractOptionalString($data, 'payment_method')
                ?? self::extractOptionalString($data['additional_info'] ?? [], 'payment_method'),
            paymentMethodCode: self::extractOptionalString($data, 'payment_method_code')
                ?? self::extractOptionalString($data['additional_info'] ?? [], 'payment_method_code'),
            additionalInfo: $data['additional_info'] ?? []
        );
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->customerIdentification)) {
            $errors['customer_identification'] = 'Identificación del cliente es requerida';
        }

        if (empty($this->customerName)) {
            $errors['customer_name'] = 'Nombre del cliente es requerido';
        }

        if (empty($this->items)) {
            $errors['items'] = 'Debe incluir al menos un ítem';
        }

        foreach ($this->items as $index => $item) {
            if (!isset($item['description']) || empty($item['description'])) {
                $errors["items.$index.description"] = 'Descripción del ítem es requerida';
            }
            if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                $errors["items.$index.quantity"] = 'Cantidad debe ser mayor a 0';
            }
            if (!isset($item['unit_price']) || $item['unit_price'] < 0) {
                $errors["items.$index.unit_price"] = 'Precio unitario es requerido';
            }
        }

        return $errors;
    }

    public function toArray(): array
    {
        return [
            'customer_identification' => $this->customerIdentification,
            'customer_name' => $this->customerName,
            'customer_address' => $this->customerAddress,
            'customer_email' => $this->customerEmail,
            'items' => $this->items,
            'payment_method' => $this->paymentMethod,
            'payment_method_code' => $this->paymentMethodCode,
            'additional_info' => $this->additionalInfo,
        ];
    }

    private static function extractOptionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = trim((string) $data[$key]);

        return $value !== '' ? $value : null;
    }
}
