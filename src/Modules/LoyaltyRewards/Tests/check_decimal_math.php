<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use App\Modules\LoyaltyRewards\Domain\DecimalMath;

if (!extension_loaded('bcmath')) {
    fwrite(STDOUT, "[SKIP] ext-bcmath no esta disponible en este runtime; el build QA debe ejecutar este test.\n");
    exit(0);
}

$assertSame = static function (mixed $expected, mixed $actual, string $name): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: esperado=%s actual=%s', $name, var_export($expected, true), var_export($actual, true)));
    }
};
$assertThrows = static function (callable $callback, string $name): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($name . ': se esperaba InvalidArgumentException.');
};
$money = static fn(int $minor): string => sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
$factor = static fn(int $scaled): string => sprintf('%d.%04d', intdiv($scaled, 10000), $scaled % 10000);

$assertSame(3, DecimalMath::calculatePoints('0.30', '0.10', '1', '1', DecimalMath::ROUND_FLOOR), '0.30/0.10 floor');
$assertSame(101, DecimalMath::calculatePoints('10.10', '0.10', '1', '1', DecimalMath::ROUND_FLOOR), '10.10/0.10 floor');
$assertSame(2, DecimalMath::calculatePoints('1.00', '0.40', '1', '1', DecimalMath::ROUND_FLOOR), 'borde floor');
$assertSame(3, DecimalMath::calculatePoints('1.00', '0.40', '1', '1', DecimalMath::ROUND_HALF_UP), 'borde half-up');
$assertSame(3, DecimalMath::calculatePoints('1.00', '0.40', '1', '1', DecimalMath::ROUND_CEIL), 'borde ceil');
$assertSame(20000, DecimalMath::capPoints(25000, 20000), 'cap maximo');
$assertThrows(static fn() => DecimalMath::money('1e3'), 'notacion cientifica');
$assertThrows(static fn() => DecimalMath::money('1.001'), 'escala monetaria');
$assertThrows(static fn() => DecimalMath::factor('1.00001'), 'escala de factor');
$assertThrows(static fn() => DecimalMath::money('9999999999999.00'), 'overflow decimal');

mt_srand(20260713);
for ($case = 0; $case < 10000; $case++) {
    $amountMinor = mt_rand(1, 1_000_000);
    $amountPerScaled = mt_rand(1, 100_000);
    $pointsScaled = mt_rand(1, 50_000);
    $multiplierScaled = mt_rand(1, 30_000);
    $numerator = $amountMinor * $pointsScaled * $multiplierScaled;
    // amountMinor/100 * points/10000 * multiplier/10000
    // dividido para amountPerUnit/10000.
    $denominator = $amountPerScaled * 1_000_000;
    $floor = intdiv($numerator, $denominator);
    $ceil = $floor + (($numerator % $denominator) === 0 ? 0 : 1);
    $halfUp = intdiv($numerator + intdiv($denominator, 2), $denominator);
    $args = [
        $money($amountMinor),
        $factor($amountPerScaled),
        $factor($pointsScaled),
        $factor($multiplierScaled),
    ];

    $assertSame($floor, DecimalMath::calculatePoints(...[...$args, DecimalMath::ROUND_FLOOR]), "oracle floor {$case}");
    $assertSame($ceil, DecimalMath::calculatePoints(...[...$args, DecimalMath::ROUND_CEIL]), "oracle ceil {$case}");
    $assertSame($halfUp, DecimalMath::calculatePoints(...[...$args, DecimalMath::ROUND_HALF_UP]), "oracle half-up {$case}");
}

fwrite(STDOUT, "[OK] DecimalMath: casos exactos, redondeo, cap, validaciones y 10000 casos oracle.\n");
