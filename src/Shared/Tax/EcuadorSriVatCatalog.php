<?php

declare(strict_types=1);

namespace App\Shared\Tax;

use InvalidArgumentException;

/**
 * Canonical Ecuador SRI IVA percentage-code catalogue.
 *
 * Rates are percentages (for example 15 means 15%). Treatment is kept
 * separate because both zero-rated and exempt lines have a numeric rate of
 * zero but require different SRI percentage codes.
 */
final class EcuadorSriVatCatalog
{
    public const TAX_CODE = '2';

    public const TREATMENT_TAXED = 'taxed';
    public const TREATMENT_ZERO_RATED = 'zero-rated';
    public const TREATMENT_EXEMPT = 'exempt';

    /** @var array<string, string> */
    private const RATE_TO_PERCENTAGE_CODE = [
        '0.00' => '0',
        '5.00' => '5',
        '12.00' => '2',
        '13.00' => '10',
        '14.00' => '3',
        '15.00' => '4',
    ];

    /** @var array<string, float> */
    private const PERCENTAGE_CODE_TO_RATE = [
        '0' => 0.0,
        '5' => 5.0,
        '2' => 12.0,
        '10' => 13.0,
        '3' => 14.0,
        '4' => 15.0,
        '7' => 0.0,
    ];

    public static function normalizeRate(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = is_string($value)
            ? trim(str_replace(',', '.', $value))
            : $value;
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $rate = round((float) $normalized, 2);
        if (!is_finite($rate) || $rate < 0.0 || $rate > 100.0) {
            return null;
        }

        return $rate;
    }

    public static function assertSupportedRate(mixed $value): float
    {
        $rate = self::normalizeRate($value);
        if ($rate === null || !array_key_exists(self::rateKey($rate), self::RATE_TO_PERCENTAGE_CODE)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Ecuador SRI IVA rate: %s. Supported rates: %s.',
                self::displayValue($value),
                implode(', ', array_map(
                    static fn(float $supported): string => self::formatRate($supported) . '%',
                    self::supportedRates()
                ))
            ));
        }

        return $rate;
    }

    public static function normalizeTreatment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim(str_replace('_', '-', (string) $value)));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            self::TREATMENT_TAXED, 'taxable' => self::TREATMENT_TAXED,
            self::TREATMENT_ZERO_RATED, 'zero', 'zero-rate' => self::TREATMENT_ZERO_RATED,
            self::TREATMENT_EXEMPT, 'tax-exempt' => self::TREATMENT_EXEMPT,
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported Ecuador SRI IVA treatment: %s.',
                self::displayValue($value)
            )),
        };
    }

    public static function percentageCode(mixed $rate, string $treatment): string
    {
        $supportedRate = self::assertSupportedRate($rate);
        $normalizedTreatment = self::normalizeTreatment($treatment);
        if ($normalizedTreatment === null) {
            throw new InvalidArgumentException('Ecuador SRI IVA treatment is required.');
        }

        if ($normalizedTreatment === self::TREATMENT_EXEMPT) {
            self::assertZeroRate($supportedRate, $normalizedTreatment);
            return '7';
        }

        if ($normalizedTreatment === self::TREATMENT_ZERO_RATED) {
            self::assertZeroRate($supportedRate, $normalizedTreatment);
            return '0';
        }

        if ($supportedRate === 0.0) {
            throw new InvalidArgumentException('A 0% IVA line must use zero-rated or exempt treatment.');
        }

        return self::RATE_TO_PERCENTAGE_CODE[self::rateKey($supportedRate)];
    }

    public static function assertCodeMatches(mixed $rate, string $treatment, mixed $code): string
    {
        $normalizedCode = trim((string) $code);
        $expected = self::percentageCode($rate, $treatment);
        if ($normalizedCode === '' || !hash_equals($expected, $normalizedCode)) {
            throw new InvalidArgumentException(sprintf(
                'SRI IVA percentage code %s does not match rate %s%% and treatment %s; expected %s.',
                $normalizedCode === '' ? '(empty)' : $normalizedCode,
                self::formatRate(self::assertSupportedRate($rate)),
                self::normalizeTreatment($treatment),
                $expected
            ));
        }

        return $normalizedCode;
    }

    public static function inferTreatment(mixed $rate, mixed $percentageCode = null): string
    {
        $supportedRate = self::assertSupportedRate($rate);
        $normalizedCode = trim((string) $percentageCode);

        if ($normalizedCode === '7') {
            self::assertZeroRate($supportedRate, self::TREATMENT_EXEMPT);
            return self::TREATMENT_EXEMPT;
        }
        if ($supportedRate === 0.0) {
            if ($normalizedCode !== '' && $normalizedCode !== '0') {
                throw new InvalidArgumentException(sprintf(
                    'SRI IVA percentage code %s is invalid for a 0%% line.',
                    $normalizedCode
                ));
            }
            return self::TREATMENT_ZERO_RATED;
        }

        if ($normalizedCode !== '') {
            $expected = self::RATE_TO_PERCENTAGE_CODE[self::rateKey($supportedRate)];
            if (!hash_equals($expected, $normalizedCode)) {
                throw new InvalidArgumentException(sprintf(
                    'SRI IVA percentage code %s does not match rate %s%%; expected %s.',
                    $normalizedCode,
                    self::formatRate($supportedRate),
                    $expected
                ));
            }
        }

        return self::TREATMENT_TAXED;
    }

    public static function rateForPercentageCode(mixed $code): float
    {
        $normalizedCode = trim((string) $code);
        if (!array_key_exists($normalizedCode, self::PERCENTAGE_CODE_TO_RATE)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Ecuador SRI IVA percentage code: %s.',
                self::displayValue($code)
            ));
        }

        return self::PERCENTAGE_CODE_TO_RATE[$normalizedCode];
    }

    /** @return list<float> */
    public static function supportedRates(): array
    {
        return [0.0, 5.0, 12.0, 13.0, 14.0, 15.0];
    }

    public static function label(mixed $rate, string $treatment): string
    {
        $supportedRate = self::assertSupportedRate($rate);
        $normalizedTreatment = self::normalizeTreatment($treatment);
        if ($normalizedTreatment === self::TREATMENT_EXEMPT) {
            return 'Exento de IVA';
        }
        if ($normalizedTreatment === self::TREATMENT_ZERO_RATED) {
            return 'IVA 0%';
        }

        return 'IVA ' . self::formatRate($supportedRate) . '%';
    }

    private static function assertZeroRate(float $rate, string $treatment): void
    {
        if ($rate !== 0.0) {
            throw new InvalidArgumentException(sprintf(
                'IVA treatment %s requires a 0%% rate; received %s%%.',
                $treatment,
                self::formatRate($rate)
            ));
        }
    }

    private static function rateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    private static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private static function displayValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        return get_debug_type($value);
    }
}
