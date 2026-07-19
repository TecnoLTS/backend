<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

final class MailTransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MAIL_TRANSPORT_FAILED',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
