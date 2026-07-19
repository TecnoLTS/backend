<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

final readonly class MailDeliveryResult
{
    public function __construct(
        public string $transport,
        public ?string $providerMessageId = null
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $transport)) {
            throw new \InvalidArgumentException('Mailer transport identifier is invalid.');
        }
    }
}
