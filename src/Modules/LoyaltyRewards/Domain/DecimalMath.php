<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Domain;

/**
 * Aritmetica decimal cerrada para operaciones con valor economico.
 *
 * Nunca convierte a float para calcular. Los float que provienen de JSON se
 * convierten inmediatamente a su representacion decimal corta y desde ese
 * punto toda la operacion usa BCMath.
 */
final class DecimalMath
{
    public const ROUND_FLOOR = 'floor';
    public const ROUND_HALF_UP = 'half-up';
    public const ROUND_CEIL = 'ceil';

    private const MAX_INTEGER_DIGITS = 12;
    private const INTERNAL_SCALE = 16;

    public static function money(mixed $value, string $field = 'monto'): string
    {
        return self::normalize($value, 2, $field, true);
    }

    public static function nonNegativeMoney(mixed $value, string $field = 'monto'): string
    {
        return self::normalize($value, 2, $field, false);
    }

    public static function factor(mixed $value, string $field = 'factor'): string
    {
        return self::normalize($value, 4, $field, true);
    }

    public static function nonNegativeFactor(mixed $value, string $field = 'factor'): string
    {
        return self::normalize($value, 4, $field, false);
    }

    public static function moneyToMinorUnits(mixed $value, string $field = 'monto'): int
    {
        $decimal = self::money($value, $field);
        $minor = bcmul($decimal, '100', 0);

        return self::boundedInteger($minor, $field);
    }

    public static function moneyFromMinorUnits(mixed $value, string $field = 'monto'): string
    {
        self::assertAvailable();
        if (is_int($value)) {
            $minor = (string)$value;
        } elseif (is_string($value)) {
            $minor = trim($value);
        } else {
            throw new \InvalidArgumentException("{$field} en centavos debe ser un entero.");
        }
        if (preg_match('/^[0-9]+$/D', $minor) !== 1 || bccomp($minor, '0', 0) <= 0) {
            throw new \InvalidArgumentException("{$field} en centavos debe ser mayor a cero.");
        }

        return self::money(bcdiv($minor, '100', 2), $field);
    }

    public static function compare(string $left, string $right, int $scale = 4): int
    {
        self::assertAvailable();

        return bccomp($left, $right, $scale);
    }

    public static function calculatePoints(
        string $amount,
        string $amountPerUnit,
        string $pointsPerUnit,
        string $multiplier,
        string $roundingMode
    ): int {
        self::assertAvailable();
        if (bccomp($amountPerUnit, '0', 4) <= 0 || bccomp($pointsPerUnit, '0', 4) <= 0 || bccomp($multiplier, '0', 4) <= 0) {
            throw new \InvalidArgumentException('La formula de puntos requiere factores mayores a cero.');
        }

        $numerator = bcmul(
            bcmul($amount, $pointsPerUnit, self::INTERNAL_SCALE),
            $multiplier,
            self::INTERNAL_SCALE
        );
        $raw = bcdiv($numerator, $amountPerUnit, self::INTERNAL_SCALE);

        return self::roundPositiveInteger($raw, $roundingMode);
    }

    public static function capPoints(int $points, int $maximum): int
    {
        if ($points < 0 || $maximum < 1) {
            throw new \InvalidArgumentException('El limite de puntos debe ser positivo.');
        }

        return min($points, $maximum);
    }

    public static function roundPositiveInteger(string $value, string $mode): int
    {
        self::assertAvailable();
        if (bccomp($value, '0', self::INTERNAL_SCALE) < 0) {
            throw new \InvalidArgumentException('No se puede redondear una cantidad negativa de puntos.');
        }

        $integer = bcadd($value, '0', 0);
        $hasFraction = bccomp($value, $integer, self::INTERNAL_SCALE) !== 0;
        $rounded = match ($mode) {
            self::ROUND_CEIL => $hasFraction ? bcadd($integer, '1', 0) : $integer,
            self::ROUND_HALF_UP, 'round' => bcadd($value, '0.5', 0),
            self::ROUND_FLOOR => $integer,
            default => throw new \InvalidArgumentException('Modo de redondeo decimal no permitido.'),
        };

        return self::boundedInteger($rounded, 'puntos calculados');
    }

    private static function normalize(mixed $value, int $maxScale, string $field, bool $strictlyPositive): string
    {
        self::assertAvailable();
        if (is_int($value)) {
            $raw = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException("{$field} no es un numero finito.");
            }
            // PHP usa una representacion decimal de ida y vuelta; no se hace
            // ninguna operacion aritmetica con este float.
            $raw = (string)$value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            throw new \InvalidArgumentException("{$field} debe ser un numero decimal.");
        }

        if ($raw === '' || preg_match('/^[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw) !== 1) {
            throw new \InvalidArgumentException("{$field} debe usar notacion decimal simple, sin exponentes.");
        }
        if (str_starts_with($raw, '+')) {
            $raw = substr($raw, 1);
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative ? substr($raw, 1) : $raw;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        if (strlen(ltrim($integer, '0')) > self::MAX_INTEGER_DIGITS) {
            throw new \InvalidArgumentException("{$field} excede el limite permitido.");
        }
        if (strlen($fraction) > $maxScale) {
            throw new \InvalidArgumentException("{$field} admite maximo {$maxScale} decimales.");
        }

        $normalized = ($negative ? '-' : '') . ($integer === '' ? '0' : $integer);
        if ($maxScale > 0) {
            $normalized .= '.' . str_pad($fraction, $maxScale, '0');
        }
        $comparison = bccomp($normalized, '0', $maxScale);
        if (($strictlyPositive && $comparison <= 0) || (!$strictlyPositive && $comparison < 0)) {
            $qualifier = $strictlyPositive ? 'mayor a cero' : 'no negativo';
            throw new \InvalidArgumentException("{$field} debe ser {$qualifier}.");
        }

        return $normalized;
    }

    private static function boundedInteger(string $value, string $field): int
    {
        $normalized = ltrim($value, '+');
        if (preg_match('/^-?[0-9]+$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException("{$field} no produjo un entero valido.");
        }
        if (bccomp($normalized, (string)PHP_INT_MAX, 0) > 0 || bccomp($normalized, (string)PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException("{$field} excede la capacidad numerica del sistema.");
        }

        return (int)$normalized;
    }

    private static function assertAvailable(): void
    {
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException('La extension ext-bcmath es obligatoria para calcular puntos.');
        }
    }
}
