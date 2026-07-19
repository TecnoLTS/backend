<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

final class CommerceBillingTransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $deliveryUnknown,
        public readonly ?int $httpStatus = null,
        public readonly string $errorCode = 'BILLING_HTTP_FAILED'
    ) {
        parent::__construct($message);
    }
}
