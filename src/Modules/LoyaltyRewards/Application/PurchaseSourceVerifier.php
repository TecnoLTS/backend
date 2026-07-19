<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Application;

use App\Modules\LoyaltyRewards\Application\Ports\BillingPurchaseSource;
use App\Modules\LoyaltyRewards\Application\Ports\EcommercePurchaseSource;
use App\Modules\LoyaltyRewards\Domain\DecimalMath;
use App\Modules\LoyaltyRewards\Domain\PurchaseVerificationException;
use App\Modules\LoyaltyRewards\Domain\ReferenceNormalizer;

final class PurchaseSourceVerifier
{
    public const STAFF_POS_SOURCE = 'staff_pos';

    public function __construct(
        private readonly EcommercePurchaseSource $ecommercePurchases,
        private readonly BillingPurchaseSource $billingPurchases
    ) {
    }

    public function verify(
        string $tenantId,
        array $member,
        string $amount,
        string $currency,
        string $reference,
        array $payload,
        ?array $trustedContext = null
    ): array {
        $reference = ReferenceNormalizer::normalize($reference);
        $currency = strtoupper(trim($currency));
        if ($currency !== 'USD') {
            throw $this->mismatch('purchase_currency_mismatch', 'La moneda de la compra no coincide con el programa.', [
                'expectedCurrency' => 'USD',
                'receivedCurrency' => $currency,
            ]);
        }

        if (($trustedContext['verified'] ?? false) === true) {
            $type = strtolower(trim((string)($trustedContext['type'] ?? '')));
            if ($type === self::STAFF_POS_SOURCE) {
                $actorId = trim((string)($trustedContext['actorId'] ?? ''));
                if ($actorId === '' || str_starts_with($actorId, 'api:')) {
                    throw $this->mismatch(
                        'purchase_staff_pos_context_invalid',
                        'La venta de caja no tiene un operador autenticado valido.',
                        [],
                        403
                    );
                }

                return [
                    'type' => self::STAFF_POS_SOURCE,
                    'verified' => false,
                    'authorized' => true,
                    'sourceReference' => $reference,
                    'actorId' => $actorId,
                    'verificationMethod' => 'authenticated_staff_rbac',
                ];
            }
            if ($type === 'pos') {
                return [
                    'type' => 'pos',
                    'verified' => true,
                    'sourceReference' => $reference,
                    'clientId' => (string)($trustedContext['clientId'] ?? ''),
                    'verifiedAt' => gmdate(DATE_ATOM),
                ];
            }
            if ($type === 'qa_fixture' && $this->isQa() && str_starts_with((string)($trustedContext['actorId'] ?? ''), 'system:test')) {
                return [
                    'type' => 'qa_fixture',
                    'verified' => true,
                    'sourceReference' => $reference,
                    'verifiedAt' => gmdate(DATE_ATOM),
                ];
            }
        }

        $requestedSource = strtolower(trim((string)($payload['purchaseSource'] ?? $payload['purchase_source'] ?? $payload['source'] ?? 'auto')));
        $boundClientId = trim((string)($trustedContext['clientId'] ?? ''));
        $boundClientSource = strtolower(trim((string)($trustedContext['type'] ?? $trustedContext['source'] ?? '')));
        if ($boundClientId !== '') {
            if (!in_array($boundClientSource, ['ecommerce', 'billing'], true)) {
                throw $this->mismatch('purchase_source_not_verifiable', 'La fuente del cliente API no tiene un verificador de compras confiable.', [
                    'apiClientId' => mb_substr($boundClientId, 0, 160),
                    'clientSource' => mb_substr($boundClientSource, 0, 32),
                ], 403);
            }
            if (!in_array($requestedSource, ['', 'auto', $boundClientSource], true)) {
                throw $this->mismatch('purchase_source_client_mismatch', 'La credencial no puede acreditar compras de otra fuente.', [
                    'apiClientId' => mb_substr($boundClientId, 0, 160),
                    'clientSource' => $boundClientSource,
                    'requestedSource' => mb_substr($requestedSource, 0, 32),
                ], 403);
            }
            $requestedSource = $boundClientSource;
        }
        if (!in_array($requestedSource, ['auto', 'ecommerce', 'billing'], true)) {
            throw $this->mismatch('purchase_source_not_verifiable', 'La fuente indicada no permite acreditar puntos.', [
                'source' => $requestedSource,
            ]);
        }

        $errors = [];
        if (in_array($requestedSource, ['auto', 'ecommerce'], true)) {
            try {
                return $this->verifyEcommerce($tenantId, $member, $amount, $currency, $reference);
            } catch (PurchaseVerificationException $exception) {
                $errors['ecommerce'] = $exception->riskType();
                if ($requestedSource === 'ecommerce') {
                    throw $exception;
                }
            }
        }
        if (in_array($requestedSource, ['auto', 'billing'], true)) {
            try {
                return $this->verifyBilling($tenantId, $member, $amount, $currency, $reference);
            } catch (PurchaseVerificationException $exception) {
                $errors['billing'] = $exception->riskType();
                if ($requestedSource === 'billing') {
                    throw $exception;
                }
            }
        }

        throw $this->mismatch(
            'purchase_source_not_found',
            'No se encontro el comprobante en Ecommerce ni Facturacion; no se acreditaron puntos.',
            ['attemptedSources' => array_keys($errors), 'results' => $errors],
            409
        );
    }

    private function verifyEcommerce(string $tenantId, array $member, string $amount, string $currency, string $reference): array
    {
        $matches = $this->ecommercePurchases->findMatches($tenantId, $reference);
        if (count($matches) !== 1) {
            throw $this->mismatch('purchase_ecommerce_not_found', 'La orden ecommerce no existe o no es univoca para este tenant.', [
                'matches' => count($matches),
            ], 409);
        }
        $order = $matches->single();
        if ($order === null) {
            throw new \LogicException('El puerto ecommerce devolvio un conteo incoherente.');
        }
        if (!hash_equals($tenantId, trim((string)($order['source_tenant_id'] ?? '')))) {
            throw $this->mismatch('purchase_ecommerce_tenant_mismatch', 'La orden ecommerce pertenece a otro tenant.', [], 409);
        }
        if (!in_array(strtolower((string)$order['status']), ['completed', 'delivered'], true)) {
            throw $this->mismatch('purchase_ecommerce_status_mismatch', 'La orden ecommerce aun no esta completada o entregada.', [
                'sourceStatus' => (string)$order['status'],
            ], 409);
        }
        $sourceAmount = DecimalMath::money((string)$order['total'], 'total ecommerce');
        if (DecimalMath::compare($sourceAmount, $amount, 2) !== 0) {
            throw $this->mismatch('purchase_amount_mismatch', 'El monto no coincide con la orden ecommerce.', [
                'sourceAmount' => $sourceAmount,
                'receivedAmount' => $amount,
            ], 409);
        }
        if (!$this->matchesEcommerceCustomer($member, $order)) {
            throw $this->mismatch('purchase_customer_mismatch', 'La orden ecommerce pertenece a otro cliente.', [], 409);
        }

        return [
            'type' => 'ecommerce',
            'verified' => true,
            'sourceId' => (string)$order['id'],
            'sourceReference' => $reference,
            'amount' => $sourceAmount,
            'currency' => $currency,
            'status' => strtolower((string)$order['status']),
            'verifiedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function verifyBilling(string $tenantId, array $member, string $amount, string $currency, string $reference): array
    {
        $matches = $this->billingPurchases->findMatches($tenantId, $reference);
        if (count($matches) !== 1) {
            throw $this->mismatch('purchase_billing_not_found', 'El comprobante fiscal no existe o no es univoco para este tenant.', [
                'matches' => count($matches),
            ], 409);
        }
        $invoice = $matches->single();
        if ($invoice === null) {
            throw new \LogicException('El puerto Billing devolvio un conteo incoherente.');
        }
        if (
            !hash_equals($tenantId, trim((string)($invoice['source_tenant_id'] ?? '')))
            || !hash_equals($tenantId, trim((string)($invoice['billing_customer_tenant_id'] ?? '')))
        ) {
            throw $this->mismatch('purchase_billing_tenant_mismatch', 'El comprobante fiscal pertenece a otro tenant.', [], 409);
        }
        $authorized = strtoupper((string)$invoice['sri_status']) === 'AUTORIZADO'
            && filter_var($invoice['authorized_xml_received'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$authorized) {
            throw $this->mismatch('purchase_billing_not_authorized', 'El comprobante fiscal no esta autorizado.', [
                'sourceStatus' => (string)$invoice['sri_status'],
            ], 409);
        }
        $sourceAmount = DecimalMath::money((string)$invoice['total_with_tax'], 'total fiscal');
        if (DecimalMath::compare($sourceAmount, $amount, 2) !== 0) {
            throw $this->mismatch('purchase_amount_mismatch', 'El monto no coincide con el comprobante fiscal.', [
                'sourceAmount' => $sourceAmount,
                'receivedAmount' => $amount,
            ], 409);
        }
        if (!$this->matchesBillingCustomer($member, $invoice)) {
            throw $this->mismatch('purchase_customer_mismatch', 'El comprobante fiscal pertenece a otro cliente.', [], 409);
        }

        return [
            'type' => 'billing',
            'verified' => true,
            'sourceId' => (string)$invoice['id'],
            'sourceReference' => $reference,
            'amount' => $sourceAmount,
            'currency' => $currency,
            'status' => 'authorized',
            'verifiedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function matchesEcommerceCustomer(array $member, array $order): bool
    {
        $externalId = trim((string)($member['external_customer_id'] ?? ''));
        if ($externalId !== '' && in_array($externalId, [(string)($order['customer_id'] ?? ''), (string)($order['user_id'] ?? '')], true)) {
            return true;
        }
        $address = $order['billing_address'] ?? [];
        if (is_string($address)) {
            $address = json_decode($address, true) ?: [];
        }
        $sourceEmail = mb_strtolower(trim((string)($address['email'] ?? $address['customerEmail'] ?? '')));

        return $sourceEmail !== '' && hash_equals(mb_strtolower(trim((string)($member['email'] ?? ''))), $sourceEmail);
    }

    private function matchesBillingCustomer(array $member, array $invoice): bool
    {
        $metadata = $member['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }
        $identification = preg_replace('/\D+/', '', (string)($metadata['identification'] ?? $metadata['documentNumber'] ?? '')) ?? '';
        $sourceIdentification = preg_replace('/\D+/', '', (string)($invoice['identification'] ?? '')) ?? '';
        if ($identification !== '' && $sourceIdentification !== '' && hash_equals($identification, $sourceIdentification)) {
            return true;
        }
        $email = mb_strtolower(trim((string)($member['email'] ?? '')));
        $sourceEmail = mb_strtolower(trim((string)($invoice['email'] ?? '')));

        return $email !== '' && $sourceEmail !== '' && hash_equals($email, $sourceEmail);
    }

    private function mismatch(string $type, string $message, array $metadata = [], int $httpStatus = 422): PurchaseVerificationException
    {
        return new PurchaseVerificationException($message, $type, $metadata, $httpStatus);
    }

    private function isQa(): bool
    {
        return strtolower(trim((string)($_ENV['APP_ENV'] ?? $_ENV['ENTORNO_MODE'] ?? ''))) === 'qa';
    }
}
