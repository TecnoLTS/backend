<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Ports;

use BillingService\Billing\Domain\Entities\Invoice;

interface XmlBuilderInterface
{
    /**
     * Construye un XML de factura según especificación del SRI
     *
     * @param Invoice $invoice Entidad de factura
     * @return string XML generado (sin firmar)
     * @throws \BillingService\Billing\Domain\Exceptions\SriException
     */
    public function buildInvoiceXml(Invoice $invoice): string;
}
