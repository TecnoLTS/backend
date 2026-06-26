<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Ports;

interface SriGatewayInterface
{
    /**
     * Envía un comprobante al SRI para su recepción
     *
     * @param string $signedXml XML firmado
     * @return array Respuesta del SRI con estado de recepción
     * @throws \BillingService\Billing\Domain\Exceptions\SriException
     */
    public function sendDocument(string $signedXml): array;

    /**
     * Consulta el estado de autorización de un comprobante
     *
     * @param string $accessKey Clave de acceso de 49 dígitos
     * @return array Estado y datos de autorización
     * @throws \BillingService\Billing\Domain\Exceptions\SriException
     */
    public function checkAuthorization(string $accessKey): array;
}
