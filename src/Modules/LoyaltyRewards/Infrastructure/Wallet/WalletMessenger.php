<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/** Puerto minimo para enviar un mensaje push a un pase; implementado por
 *  GoogleWalletService y por dobles de prueba. */
interface WalletMessenger {
    /** @return array{objectId: string, messageId: string, messageType?: string} */
    public function addMessage(string $accountId, string $header, string $body): array;
}
