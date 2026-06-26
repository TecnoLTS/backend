<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Ports;

interface DocumentSignerInterface
{
    /**
     * Firma un documento XML con certificado digital XADES-BES
     *
     * @param string $xml XML sin firmar
     * @return string XML firmado
     * @throws \BillingService\Billing\Domain\Exceptions\SriException
     */
    public function sign(string $xml): string;

    /**
     * Verifica que la firma de un documento XML sea válida
     *
     * @param string $signedXml XML firmado
     * @return bool True si la firma es válida
     */
    public function verify(string $signedXml): bool;
}
