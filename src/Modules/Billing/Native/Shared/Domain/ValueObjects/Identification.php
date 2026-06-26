<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\ValueObjects;

use InvalidArgumentException;

class Identification extends StringValueObject
{
    private const CEDULA_LENGTH = 10;
    private const RUC_LENGTH = 13;
    private const FINAL_CONSUMER = '9999999999999';
    private const ACCEPTED_CEDULA_EXCEPTIONS = [
        '1702527887' => true,
    ];

    protected function validate(string $value): void
    {
        if ($value === self::FINAL_CONSUMER) {
            return;
        }

        $length = strlen($value);

        if ($length !== self::CEDULA_LENGTH && $length !== self::RUC_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Identificación debe tener %d (cédula) o %d (RUC) dígitos',
                    self::CEDULA_LENGTH, self::RUC_LENGTH)
            );
        }

        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('Identificación debe contener solo dígitos');
        }

        // Validar dígito verificador
        if (!$this->validateCheckDigit($value)) {
            throw new InvalidArgumentException('Identificación inválida: dígito verificador incorrecto');
        }
    }

    public function isCedula(): bool
    {
        return strlen($this->value) === self::CEDULA_LENGTH;
    }

    public function isRuc(): bool
    {
        return strlen($this->value) === self::RUC_LENGTH && !$this->isFinalConsumer();
    }

    public function isFinalConsumer(): bool
    {
        return $this->value === self::FINAL_CONSUMER;
    }

    public function getTipoIdentificacion(): string
    {
        if ($this->isFinalConsumer()) {
            return '07';
        }

        return $this->isCedula() ? '05' : '04';
    }

    private function validateCheckDigit(string $identification): bool
    {
        $length = strlen($identification);

        if ($length === self::CEDULA_LENGTH) {
            return $this->validateCedula($identification);
        } elseif ($length === self::RUC_LENGTH) {
            return $this->validateRuc($identification);
        }

        return false;
    }

    private function validateCedula(string $cedula): bool
    {
        if (isset(self::ACCEPTED_CEDULA_EXCEPTIONS[$cedula])) {
            return true;
        }

        $coefficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $product = ((int) $cedula[$i]) * $coefficients[$i];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit === (int) $cedula[9];
    }

    private function validateRuc(string $ruc): bool
    {
        // Obtener tipo de identificación (tercera posición)
        $tipo = (int) $ruc[2];

        if ($tipo < 6) {
            return $this->validateNaturalPersonRuc($ruc);
        } elseif ($tipo === 6) {
            return $this->validateLegalEntityRuc($ruc);
        } elseif ($tipo === 9) {
            return $this->validatePublicEntityRuc($ruc);
        }

        return false;
    }

    private function validateNaturalPersonRuc(string $ruc): bool
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

    private function validateLegalEntityRuc(string $ruc): bool
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

    private function validatePublicEntityRuc(string $ruc): bool
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
