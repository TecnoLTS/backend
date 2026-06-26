<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Dto\Response;

class InvoiceResponse
{
    public function __construct(
        public readonly string $accessKey,
        public readonly string $sequential,
        public readonly string $issueDate,
        public readonly float $total,
        public readonly string $status,
        public readonly ?string $authorizationNumber = null,
        public readonly ?string $authorizationDate = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $xmlUrl = null
    ) {}

    public function toArray(): array
    {
        return [
            'access_key' => $this->accessKey,
            'sequential' => $this->sequential,
            'issue_date' => $this->issueDate,
            'total' => $this->total,
            'status' => $this->status,
            'authorization_number' => $this->authorizationNumber,
            'authorization_date' => $this->authorizationDate,
            'pdf_url' => $this->pdfUrl,
            'xml_url' => $this->xmlUrl,
        ];
    }

    public static function fromInvoice(\BillingService\Billing\Domain\Entities\Invoice $invoice): self
    {
        return new self(
            accessKey: $invoice->accessKey()->value(),
            sequential: sprintf('%s-%s-%s', $invoice->establishment(), $invoice->emissionPoint(), $invoice->sequential()),
            issueDate: $invoice->issueDate()->format('Y-m-d'),
            total: $invoice->total()->amount(),
            status: $invoice->status(),
            authorizationNumber: $invoice->authorizationNumber(),
            authorizationDate: $invoice->authorizationDate()?->format('Y-m-d H:i:s')
        );
    }

    public static function fromStoredInvoice(array $invoice): self
    {
        $establishment = str_pad((string) ($invoice['establishment_code'] ?? ''), 3, '0', STR_PAD_LEFT);
        $emissionPoint = str_pad((string) ($invoice['emission_point'] ?? ''), 3, '0', STR_PAD_LEFT);
        $sequential = str_pad((string) ($invoice['sequential'] ?? ''), 9, '0', STR_PAD_LEFT);

        return new self(
            accessKey: (string) ($invoice['access_key'] ?? ''),
            sequential: sprintf('%s-%s-%s', $establishment, $emissionPoint, $sequential),
            issueDate: (string) ($invoice['issue_date'] ?? ''),
            total: (float) ($invoice['total_with_tax'] ?? 0),
            status: (string) ($invoice['sri_status'] ?? 'UNKNOWN'),
            authorizationNumber: isset($invoice['authorization_number']) ? (string) $invoice['authorization_number'] : null,
            authorizationDate: isset($invoice['authorization_date']) ? (string) $invoice['authorization_date'] : null,
            pdfUrl: isset($invoice['access_key']) ? sprintf('/invoices/%s/ride.pdf', $invoice['access_key']) : null,
            xmlUrl: isset($invoice['access_key']) ? sprintf('/invoices/%s/xml', $invoice['access_key']) : null
        );
    }
}
