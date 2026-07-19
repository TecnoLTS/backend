<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

interface CommerceContactPort
{
    public function countRecentByEmail(string $email): int;

    public function countRecentByIp(?string $ipAddress): int;

    public function createMessage(array $payload): array;

    public function sendMail(
        string $recipient,
        string $subject,
        string $message,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): bool;
}
