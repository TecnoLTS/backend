<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Domain;

final class ReferenceNormalizer
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('La referencia debe ser texto.');
        }
        $reference = preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '';
        if ($reference === '') {
            throw new \InvalidArgumentException('La referencia es obligatoria.');
        }
        if (mb_strlen($reference) > 160) {
            throw new \InvalidArgumentException('La referencia excede 160 caracteres.');
        }

        return mb_strtoupper($reference, 'UTF-8');
    }
}
