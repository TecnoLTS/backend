<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final class AccessKey
{
    private const LENGTH = 49;
    private const CHECK_DIGIT_BASE = 11;
    private const CHECK_DIGIT_WEIGHTS = [2, 3, 4, 5, 6, 7];

    private function __construct(public readonly string $value)
    {
        $this->validate($value);
    }

    public static function create(
        DateTimeImmutable $date,
        string $voucherType, // 2 digits
        string $ruc, // 13 digits
        string $environment, // 1 digit
        string $series, // 6 digits (3 point of sale, 3 establishment)
        string $invoiceNumber, // 9 digits
        string $numericCode, // 8 digits
        string $issuanceType // 1 digit
    ): self {
        $datePart = $date->format('dmY');
        self::validateLength('voucherType', $voucherType, 2);
        self::validateLength('ruc', $ruc, 13);
        self::validateLength('environment', $environment, 1);
        self::validateLength('series', $series, 6);
        self::validateLength('invoiceNumber', $invoiceNumber, 9);
        self::validateLength('numericCode', $numericCode, 8);
        self::validateLength('issuanceType', $issuanceType, 1);

        $base = $datePart . $voucherType . $ruc . $environment . $series . $invoiceNumber . $numericCode . $issuanceType;
        $checkDigit = self::calculateCheckDigit($base);

        return new self($base . $checkDigit);
    }

    public static function fromValue(string $value): self
    {
        return new self($value);
    }

    // Alias para compatibilidad con la interfaz legacy
    public static function generate(
        string $date,
        string $documentType,
        string $ruc,
        string $environment,
        string $series,
        string $sequential,
        string $emissionCode
    ): self {
        $dateObj = \DateTime::createFromFormat('dmY', $date);
        if (!$dateObj) {
            throw new InvalidArgumentException('Invalid date format. Expected: dmY');
        }

        $numericCode = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);

        return self::create(
            date: DateTimeImmutable::createFromMutable($dateObj),
            voucherType: $documentType,
            ruc: $ruc,
            environment: $environment,
            series: $series,
            invoiceNumber: str_pad($sequential, 9, '0', STR_PAD_LEFT),
            numericCode: $numericCode,
            issuanceType: $emissionCode
        );
    }

    private static function validateLength(string $field, string $value, int $length): void
    {
        if (strlen($value) !== $length) {
            throw new InvalidArgumentException("Invalid length for $field. Expected $length, got " . strlen($value));
        }
    }

    private static function calculateCheckDigit(string $base): int
    {
        $sum = 0;
        $weightIndex = 0;
        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $sum += (int)$base[$i] * self::CHECK_DIGIT_WEIGHTS[$weightIndex];
            $weightIndex = ($weightIndex + 1) % count(self::CHECK_DIGIT_WEIGHTS);
        }

        $mod = $sum % self::CHECK_DIGIT_BASE;
        $checkDigit = self::CHECK_DIGIT_BASE - $mod;

        if ($checkDigit === self::CHECK_DIGIT_BASE) {
            return 0;
        }
        if ($checkDigit === 10) {
            return 1;
        }

        return $checkDigit;
    }

    private function validate(string $value): void
    {
        if (strlen($value) !== self::LENGTH) {
            throw new InvalidArgumentException('Access key must be ' . self::LENGTH . ' digits long.');
        }

        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('Access key must only contain digits.');
        }

        $base = substr($value, 0, self::LENGTH - 1);
        $checkDigit = (int)substr($value, -1);

        if (self::calculateCheckDigit($base) !== $checkDigit) {
            throw new InvalidArgumentException('Invalid access key check digit.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function value(): string
    {
        return $this->value;
    }
}
