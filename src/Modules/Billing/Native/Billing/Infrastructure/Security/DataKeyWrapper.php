<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Security;

/**
 * Boundary between the Billing envelope format and the key-management system.
 *
 * The local implementation reads a versioned keyring file. A production KMS
 * adapter can implement the same contract without exposing its KEK to Billing.
 */
interface DataKeyWrapper
{
    public function activeKeyId(): string;

    /** @return array{kid:string,nonce:string,tag:string,ciphertext:string} */
    public function wrap(string $dataKey, string $associatedData): array;

    /** @param array{kid:string,nonce:string,tag:string,ciphertext:string} $wrappedKey */
    public function unwrap(array $wrappedKey, string $associatedData): string;
}
