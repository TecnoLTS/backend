<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\ValueObjects;

use InvalidArgumentException;

class Ruc extends StringValueObject
{
    private const RUC_LENGTH = 13;

    protected function validate(string $value): void
    {
        if (strlen($value) !== self::RUC_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('RUC debe tener exactamente %d dígitos', self::RUC_LENGTH)
            );
        }

        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('RUC debe contener solo dígitos');
        }

        // Validar dígito verificador según algoritmo módulo 11
        if (!$this->validateCheckDigit($value)) {
            throw new InvalidArgumentException('RUC inválido: dígito verificador incorrecto');
        }
    }

    private function validateCheckDigit(string $ruc): bool
    {
        // Obtener tipo de identificación (tercera posición)
        $tipo = (int) $ruc[2];

        // RUC de personas naturales (tipo 0-5) o jurídicas (tipo 6-9)
        if ($tipo < 6) {
            return $this->validateNaturalPerson($ruc);
        } elseif ($tipo === 6) {
            return $this->validateLegalEntity($ruc);
        } elseif ($tipo === 9) {
            return $this->validatePublicEntity($ruc);
        }

        return false;
    }

    private function validateNaturalPerson(string $ruc): bool
    {
        $coefficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $product = ((int) $ruc[$i]) * $coefficients[$i];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit === (int) $ruc[9];
    }

    private function validateLegalEntity(string $ruc): bool
    {
        $coefficients = [3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += ((int) $ruc[$i]) * $coefficients[$i];
        }

        $checkDigit = 11 - ($sum % 11);
        if ($checkDigit === 11) $checkDigit = 0;
        if ($checkDigit === 10) return false;

        return $checkDigit === (int) $ruc[8];
    }

    private function validatePublicEntity(string $ruc): bool
    {
        $coefficients = [4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $ruc[$i]) * $coefficients[$i];
        }

        $checkDigit = 11 - ($sum % 11);
        if ($checkDigit === 11) $checkDigit = 0;
        if ($checkDigit === 10) return false;

        return $checkDigit === (int) $ruc[9];
    }
}
