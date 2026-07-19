<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\Services;

/**
 * Adds operational guidance to SRI messages without changing their original fields.
 * Unknown codes fail closed and are sent to manual review instead of being retried or
 * reissued blindly.
 */
final class SriErrorInterpreter
{
    /** @var array<string, array{category:string,retryable:bool,reissue:bool,action:string}> */
    private const RULES = [
        '35' => ['category' => 'document_validation', 'retryable' => false, 'reissue' => true, 'action' => 'Corregir el XML o los datos indicados por el SRI y emitir un comprobante nuevo.'],
        '39' => ['category' => 'signature', 'retryable' => false, 'reissue' => true, 'action' => 'Revisar certificado, vigencia y firma XAdES; luego emitir un comprobante nuevo.'],
        '43' => ['category' => 'access_key_registered', 'retryable' => false, 'reissue' => false, 'action' => 'Consultar la clave de acceso en autorización antes de decidir una reemisión.'],
        '45' => ['category' => 'sequential_registered', 'retryable' => false, 'reissue' => true, 'action' => 'Avanzar el consecutivo del mismo ambiente y reemitir una sola vez, conservando el comprobante reemplazado.'],
        '46' => ['category' => 'issuer_configuration', 'retryable' => false, 'reissue' => true, 'action' => 'Corregir el RUC o la configuración del emisor antes de emitir nuevamente.'],
        '47' => ['category' => 'document_type', 'retryable' => false, 'reissue' => true, 'action' => 'Corregir el tipo de comprobante o su código y emitir uno nuevo.'],
        '48' => ['category' => 'schema_version', 'retryable' => false, 'reissue' => true, 'action' => 'Corregir la versión o el esquema XSD del comprobante y emitir uno nuevo.'],
        '49' => ['category' => 'integration_error', 'retryable' => false, 'reissue' => false, 'action' => 'Revisar argumentos nulos en la integración; no reemitir hasta corregir el defecto.'],
        '50' => ['category' => 'sri_transient', 'retryable' => true, 'reissue' => false, 'action' => 'Reintentar la misma operación con espera incremental; no generar otra clave.'],
        '56' => ['category' => 'establishment_configuration', 'retryable' => false, 'reissue' => false, 'action' => 'Validar en el SRI el estado del establecimiento y punto de emisión antes de continuar.'],
        '70' => ['category' => 'sri_processing', 'retryable' => true, 'reissue' => false, 'action' => 'Consultar autorización con la misma clave; no emitir otro comprobante.'],
    ];

    public static function enrich(mixed $messages): mixed
    {
        if (!is_array($messages)) {
            return $messages;
        }

        if (self::isMessage($messages)) {
            $identifier = trim((string) ($messages['identificador'] ?? $messages['identifier'] ?? ''));
            $messages['diagnostico'] = self::diagnose($identifier, (string) ($messages['mensaje'] ?? $messages['message'] ?? ''));
            return $messages;
        }

        foreach ($messages as $key => $value) {
            $messages[$key] = self::enrich($value);
        }

        return $messages;
    }

    /** @return array{code:string,category:string,retryable:bool,reissue_required:bool,recommended_action:string} */
    public static function diagnose(string $identifier, string $message = ''): array
    {
        $rule = self::RULES[$identifier] ?? self::inferRule($message);

        return [
            'code' => $identifier,
            'category' => $rule['category'],
            'retryable' => $rule['retryable'],
            'reissue_required' => $rule['reissue'],
            'recommended_action' => $rule['action'],
        ];
    }

    private static function isMessage(array $value): bool
    {
        return array_key_exists('identificador', $value)
            || array_key_exists('identifier', $value)
            || (array_key_exists('mensaje', $value) && !is_array($value['mensaje']))
            || (array_key_exists('message', $value) && !is_array($value['message']));
    }

    /** @return array{category:string,retryable:bool,reissue:bool,action:string} */
    private static function inferRule(string $message): array
    {
        $normalized = self::normalize($message);
        if (str_contains($normalized, 'SECUENCIAL') && str_contains($normalized, 'REGISTR')) {
            return self::RULES['45'];
        }
        if (str_contains($normalized, 'PROCES')) {
            return self::RULES['70'];
        }
        if (str_contains($normalized, 'SERVICIO') || str_contains($normalized, 'INTERNO')) {
            return self::RULES['50'];
        }

        return [
            'category' => 'manual_review',
            'retryable' => false,
            'reissue' => false,
            'action' => 'Revisar el mensaje y la ficha técnica vigente del SRI; no reintentar ni reemitir automáticamente.',
        ];
    }

    private static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        return strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    }
}
