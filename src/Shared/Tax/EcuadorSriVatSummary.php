<?php

declare(strict_types=1);

namespace App\Shared\Tax;

use InvalidArgumentException;

/** Builds one canonical tax summary for persistence projections, XML and RIDE. */
final class EcuadorSriVatSummary
{
    /**
     * @param list<array{
     *     tax_code?: mixed,
     *     percentage_code?: mixed,
     *     rate?: mixed,
     *     treatment?: mixed,
     *     base?: mixed,
     *     amount?: mixed
     * }> $entries
     * @return array{
     *     groups: list<array{
     *         tax_code: string,
     *         percentage_code: string,
     *         rate: ?float,
     *         treatment: string,
     *         label: string,
     *         base: float,
     *         amount: float
     *     }>,
     *     subtotal_zero_rated: float,
     *     subtotal_exempt: float,
     *     subtotal_taxed: float,
     *     subtotal_15: float,
     *     iva_15: float,
     *     tax_total: float
     * }
     */
    public static function summarize(array $entries): array
    {
        /** @var array<string, array{tax_code: string, percentage_code: string, rate: ?float, treatment: string, label: string, base: float, amount: float}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Tax summary entries must be arrays.');
            }

            $taxCode = trim((string)($entry['tax_code'] ?? ''));
            $percentageCode = trim((string)($entry['percentage_code'] ?? ''));
            if ($taxCode === '' || $percentageCode === '') {
                throw new InvalidArgumentException('Tax summary entries require tax_code and percentage_code.');
            }

            $base = self::nonNegativeNumber($entry['base'] ?? 0, 'taxable base');
            $amount = self::nonNegativeNumber($entry['amount'] ?? 0, 'tax amount');

            if ($taxCode === EcuadorSriVatCatalog::TAX_CODE) {
                $rate = array_key_exists('rate', $entry) && $entry['rate'] !== null && $entry['rate'] !== ''
                    ? EcuadorSriVatCatalog::assertSupportedRate($entry['rate'])
                    : EcuadorSriVatCatalog::rateForPercentageCode($percentageCode);
                $treatment = EcuadorSriVatCatalog::normalizeTreatment($entry['treatment'] ?? null)
                    ?? EcuadorSriVatCatalog::inferTreatment($rate, $percentageCode);
                EcuadorSriVatCatalog::assertCodeMatches($rate, $treatment, $percentageCode);
                if ($rate === 0.0 && round($amount, 2) !== 0.0) {
                    throw new InvalidArgumentException('Zero-rated and exempt IVA summary entries must have zero tax amount.');
                }
                $label = EcuadorSriVatCatalog::label($rate, $treatment);
            } else {
                $rate = null;
                $treatment = 'other';
                $label = sprintf('Impuesto SRI %s/%s', $taxCode, $percentageCode);
            }

            $key = $taxCode . ':' . $percentageCode . ':' . $treatment;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tax_code' => $taxCode,
                    'percentage_code' => $percentageCode,
                    'rate' => $rate,
                    'treatment' => $treatment,
                    'label' => $label,
                    'base' => 0.0,
                    'amount' => 0.0,
                ];
            }
            $groups[$key]['base'] = round($groups[$key]['base'] + $base, 6);
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $amount, 6);
        }

        uasort($groups, static function (array $left, array $right): int {
            if ($left['tax_code'] !== $right['tax_code']) {
                return $left['tax_code'] <=> $right['tax_code'];
            }
            $leftRate = $left['rate'] ?? 999.0;
            $rightRate = $right['rate'] ?? 999.0;
            if ($leftRate !== $rightRate) {
                return $leftRate <=> $rightRate;
            }

            return $left['percentage_code'] <=> $right['percentage_code'];
        });

        $summary = [
            'groups' => array_values($groups),
            'subtotal_zero_rated' => 0.0,
            'subtotal_exempt' => 0.0,
            'subtotal_taxed' => 0.0,
            'subtotal_15' => 0.0,
            'iva_15' => 0.0,
            'tax_total' => 0.0,
        ];

        foreach ($summary['groups'] as $group) {
            $summary['tax_total'] += $group['amount'];
            if ($group['tax_code'] !== EcuadorSriVatCatalog::TAX_CODE) {
                continue;
            }
            if ($group['treatment'] === EcuadorSriVatCatalog::TREATMENT_ZERO_RATED) {
                $summary['subtotal_zero_rated'] += $group['base'];
            } elseif ($group['treatment'] === EcuadorSriVatCatalog::TREATMENT_EXEMPT) {
                $summary['subtotal_exempt'] += $group['base'];
            } else {
                $summary['subtotal_taxed'] += $group['base'];
            }
            if ($group['rate'] === 15.0) {
                $summary['subtotal_15'] += $group['base'];
                $summary['iva_15'] += $group['amount'];
            }
        }

        foreach ([
            'subtotal_zero_rated',
            'subtotal_exempt',
            'subtotal_taxed',
            'subtotal_15',
            'iva_15',
            'tax_total',
        ] as $field) {
            $summary[$field] = round((float)$summary[$field], 6);
        }

        return $summary;
    }

    private static function nonNegativeNumber(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('%s must be numeric.', ucfirst($field)));
        }
        $number = round((float)$value, 6);
        if (!is_finite($number) || $number < 0.0) {
            throw new InvalidArgumentException(sprintf('%s must be finite and non-negative.', ucfirst($field)));
        }

        return $number;
    }
}
