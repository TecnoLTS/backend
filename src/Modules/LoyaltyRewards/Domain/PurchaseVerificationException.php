<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Domain;

final class PurchaseVerificationException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        private readonly string $riskType,
        private readonly array $riskMetadata = [],
        private readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }

    public function riskType(): string
    {
        return $this->riskType;
    }

    public function riskMetadata(): array
    {
        return $this->riskMetadata;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
