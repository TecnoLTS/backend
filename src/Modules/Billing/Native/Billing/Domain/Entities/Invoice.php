<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\Entities;

use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Billing\Domain\ValueObjects\DocumentType;
use BillingService\Billing\Domain\ValueObjects\Environment;
use BillingService\Billing\Domain\ValueObjects\Money;
use BillingService\Shared\Domain\ValueObjects\Ruc;
use BillingService\Shared\Domain\ValueObjects\Identification;
use DateTimeImmutable;

class Invoice
{
    private AccessKey $accessKey;
    private Ruc $issuerRuc;
    private string $issuerBusinessName;
    private Identification $customerIdentification;
    private string $customerName;
    private string $customerAddress;
    private string $customerEmail;
    private DateTimeImmutable $issueDate;
    private Environment $environment;
    private string $establishment;
    private string $emissionPoint;
    private string $sequential;
    private array $items;
    private array $taxes;
    private string $paymentMethodCode;
    private string $paymentMethodLabel;
    private Money $subtotal;
    private Money $totalTax;
    private Money $total;
    private ?string $authorizationNumber = null;
    private ?DateTimeImmutable $authorizationDate = null;
    private string $status = 'PENDING';

    public function __construct(
        AccessKey $accessKey,
        Ruc $issuerRuc,
        string $issuerBusinessName,
        Identification $customerIdentification,
        string $customerName,
        string $customerAddress,
        string $customerEmail,
        DateTimeImmutable $issueDate,
        Environment $environment,
        string $establishment,
        string $emissionPoint,
        string $sequential,
        array $items,
        array $taxes,
        string $paymentMethodCode = '01',
        string $paymentMethodLabel = 'Sin utilizacion del sistema financiero'
    ) {
        $this->accessKey = $accessKey;
        $this->issuerRuc = $issuerRuc;
        $this->issuerBusinessName = $issuerBusinessName;
        $this->customerIdentification = $customerIdentification;
        $this->customerName = $customerName;
        $this->customerAddress = $customerAddress;
        $this->customerEmail = $customerEmail;
        $this->issueDate = $issueDate;
        $this->environment = $environment;
        $this->establishment = $establishment;
        $this->emissionPoint = $emissionPoint;
        $this->sequential = $sequential;
        $this->items = $items;
        $this->taxes = $taxes;
        $this->paymentMethodCode = $paymentMethodCode;
        $this->paymentMethodLabel = $paymentMethodLabel;

        $this->calculateTotals();
    }

    private function calculateTotals(): void
    {
        $subtotal = 0.0;
        foreach ($this->items as $item) {
            if (array_key_exists('lineSubtotal', $item)) {
                $subtotal += (float) $item['lineSubtotal'];
                continue;
            }

            $subtotal += max(0.0, ((float) ($item['quantity'] ?? 0) * (float) ($item['unitPrice'] ?? 0)) - (float) ($item['discount'] ?? 0));
        }
        $this->subtotal = new Money($subtotal);

        $totalTax = 0.0;
        foreach ($this->taxes as $tax) {
            $totalTax += $tax['amount'];
        }
        $this->totalTax = new Money($totalTax);

        $this->total = $this->subtotal->add($this->totalTax);
    }

    public function authorize(string $authorizationNumber, DateTimeImmutable $authorizationDate): void
    {
        $this->authorizationNumber = $authorizationNumber;
        $this->authorizationDate = $authorizationDate;
        $this->status = 'AUTHORIZED';
    }

    public function reject(string $reason): void
    {
        $this->status = 'REJECTED';
    }

    // Getters
    public function accessKey(): AccessKey
    {
        return $this->accessKey;
    }

    public function issuerRuc(): Ruc
    {
        return $this->issuerRuc;
    }

    public function customerIdentification(): Identification
    {
        return $this->customerIdentification;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isAuthorized(): bool
    {
        return $this->status === 'AUTHORIZED';
    }

    public function items(): array
    {
        return $this->items;
    }

    public function taxes(): array
    {
        return $this->taxes;
    }

    public function issuerBusinessName(): string
    {
        return $this->issuerBusinessName;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerAddress(): string
    {
        return $this->customerAddress;
    }

    public function customerEmail(): string
    {
        return $this->customerEmail;
    }

    public function issueDate(): DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function establishment(): string
    {
        return $this->establishment;
    }

    public function emissionPoint(): string
    {
        return $this->emissionPoint;
    }

    public function sequential(): string
    {
        return $this->sequential;
    }

    public function authorizationNumber(): ?string
    {
        return $this->authorizationNumber;
    }

    public function authorizationDate(): ?DateTimeImmutable
    {
        return $this->authorizationDate;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function totalTax(): Money
    {
        return $this->totalTax;
    }

    public function paymentMethodCode(): string
    {
        return $this->paymentMethodCode;
    }

    public function paymentMethodLabel(): string
    {
        return $this->paymentMethodLabel;
    }
}
