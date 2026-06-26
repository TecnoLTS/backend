<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use BillingService\Billing\Application\Ports\SriGatewayInterface;
use BillingService\Billing\Domain\Exceptions\SriException;
use SoapClient;
use SoapFault;
use Psr\Log\LoggerInterface;

class SoapSriConnector implements SriGatewayInterface
{
    private string $recepcionWsdl;
    private string $autorizacionWsdl;
    private LoggerInterface $logger;
    private int $maxRetries;
    private int $retryDelay;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $environment = $config['environment'];
        $this->recepcionWsdl = $config['web_services'][$environment]['recepcion'];
        $this->autorizacionWsdl = $config['web_services'][$environment]['autorizacion'];
        $this->logger = $logger;
        $this->maxRetries = max(1, min(5, (int) ($config['retry']['max_attempts'] ?? 3)));
        $this->retryDelay = max(0, min(30, (int) ($config['retry']['sri_connection_delay_seconds'] ?? 5)));
    }

    public function sendDocument(string $signedXml): array
    {
        return $this->executeWithRetry(function () use ($signedXml) {
            try {
                $client = new SoapClient($this->recepcionWsdl, [
                    'soap_version' => SOAP_1_1,
                    'trace' => 1,
                    'exceptions' => true,
                    'connection_timeout' => 30,
                ]);

                $response = $client->validarComprobante(['xml' => $signedXml]);
                $estado = $response->RespuestaRecepcionComprobante->estado ?? 'UNKNOWN';

                if ($estado === 'DEVUELTA') {
                    $mensajes = $this->extractReceptionMessages($response->RespuestaRecepcionComprobante);
                    foreach ($mensajes as $msg) {
                        if ($msg['identificador'] === '70') {
                            $this->logger->info('[SRI] Clave en procesamiento (70). Saltando a autorización.');
                            return [
                                'estado' => 'RECIBIDA',
                                'comprobantes' => $response->RespuestaRecepcionComprobante->comprobantes ?? null
                            ];
                        }
                    }
                }

                return [
                    'estado' => $estado,
                    'comprobantes' => $response->RespuestaRecepcionComprobante->comprobantes ?? null,
                ];
            } catch (SoapFault $e) {
                throw SriException::connectionFailed($e->getMessage());
            }
        });
    }

    public function checkAuthorization(string $accessKey): array
    {
        return $this->executeWithRetry(function () use ($accessKey) {
            try {
                $client = new SoapClient($this->autorizacionWsdl, [
                    'soap_version' => SOAP_1_1,
                    'trace' => 1,
                    'exceptions' => true,
                    'connection_timeout' => 30,
                ]);

                $response = $client->autorizacionComprobante(['claveAccesoComprobante' => $accessKey]);
                $resAuth = $response->RespuestaAutorizacionComprobante;

                // --- MANEJO DE OBJETO VACÍO (El caso de tu var_dump) ---
                if (!isset($resAuth->autorizaciones->autorizacion) || (int)$resAuth->numeroComprobantes === 0) {
                    $this->logger->warning('[SRI] El comprobante aún no ha sido procesado por el motor del SRI', ['access_key' => $accessKey]);
                    return [
                        'estado' => 'EN PROCESAMIENTO',
                        'mensajes' => [['mensaje' => 'Comprobante recibido pero aún no procesado']]
                    ];
                }

                $autorizaciones = $resAuth->autorizaciones->autorizacion;
                $authorization = is_array($autorizaciones) ? $autorizaciones[0] : $autorizaciones;

                return [
                    'estado' => $authorization->estado ?? 'NO AUTORIZADO',
                    'numeroAutorizacion' => $authorization->numeroAutorizacion ?? null,
                    'fechaAutorizacion' => $authorization->fechaAutorizacion ?? null,
                    'comprobante' => $authorization->comprobante ?? null,
                    'mensajes' => $this->extractMessages($authorization),
                ];

            } catch (SoapFault $e) {
                throw SriException::connectionFailed($e->getMessage());
            }
        });
    }

    private function executeWithRetry(callable $operation): array
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (SriException $e) {
                $lastException = $e;
                if ($attempt < $this->maxRetries) {
                    $this->logger->warning("[SRI] Reintentando tras error...", ['error' => $e->getMessage()]);
                    sleep($this->retryDelay);
                }
            }
        }
        throw $lastException ?? SriException::connectionFailed('Error de conexión SRI');
    }

    private function extractReceptionMessages($respuestaRecepcion): array
    {
        $messages = [];
        $comprobante = $respuestaRecepcion->comprobantes->comprobante ?? null;
        if ($comprobante && isset($comprobante->mensajes->mensaje)) {
            $mensajes = is_array($comprobante->mensajes->mensaje) ? $comprobante->mensajes->mensaje : [$comprobante->mensajes->mensaje];
            foreach ($mensajes as $m) {
                $messages[] = ['identificador' => $m->identificador ?? '', 'mensaje' => $m->mensaje ?? ''];
            }
        }
        return $messages;
    }

    private function extractMessages($authorization): array
    {
        $messages = [];
        if (isset($authorization->mensajes->mensaje)) {
            $mensajes = is_array($authorization->mensajes->mensaje) ? $authorization->mensajes->mensaje : [$authorization->mensajes->mensaje];
            foreach ($mensajes as $m) {
                $messages[] = [
                    'identificador' => $m->identificador ?? null,
                    'mensaje' => $m->mensaje ?? null,
                    'tipo' => $m->tipo ?? null,
                    'informacionAdicional' => $m->informacionAdicional ?? null,
                ];
            }
        }
        return $messages;
    }
}
