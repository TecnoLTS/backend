<?php

namespace App\Services;

class BillingApiException extends \RuntimeException {
    public function __construct(
        string $message,
        private readonly int $httpStatusCode,
        private readonly string $endpoint = ''
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    public function httpStatusCode(): int {
        return $this->httpStatusCode;
    }

    public function endpoint(): string {
        return $this->endpoint;
    }
}
