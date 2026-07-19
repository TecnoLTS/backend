<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Adapters;

use App\Modules\Commerce\Application\Ports\CommerceContactPort;
use App\Repositories\ContactMessageRepository;
use App\Services\MailService;

final class InProcessCommerceContactAdapter implements CommerceContactPort
{
    public function __construct(private readonly ContactMessageRepository $messages = new ContactMessageRepository())
    {
    }

    public function countRecentByEmail(string $email): int
    {
        return $this->messages->countRecentByEmail($email);
    }

    public function countRecentByIp(?string $ipAddress): int
    {
        return $this->messages->countRecentByIp($ipAddress);
    }

    public function createMessage(array $payload): array
    {
        return $this->messages->create($payload);
    }

    public function sendMail(
        string $recipient,
        string $subject,
        string $message,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): bool {
        return MailService::send($recipient, $subject, $message, $replyToEmail, $replyToName);
    }
}
