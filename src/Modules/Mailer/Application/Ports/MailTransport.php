<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application\Ports;

use App\Modules\Mailer\Application\MailDeliveryResult;

interface MailTransport
{
    /** @param array<string,mixed> $message */
    public function deliver(array $message): MailDeliveryResult;
}
