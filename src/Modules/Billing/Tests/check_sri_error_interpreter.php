<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Native/Billing/Application/Services/SriErrorInterpreter.php';

use BillingService\Billing\Application\Services\SriErrorInterpreter;

$sequential = SriErrorInterpreter::diagnose('45', 'ERROR SECUENCIAL REGISTRADO');
assert($sequential['category'] === 'sequential_registered');
assert($sequential['retryable'] === false);
assert($sequential['reissue_required'] === true);

$processing = SriErrorInterpreter::diagnose('70');
assert($processing['retryable'] === true);
assert($processing['reissue_required'] === false);

$unknown = SriErrorInterpreter::diagnose('999', 'Mensaje nuevo');
assert($unknown['category'] === 'manual_review');
assert($unknown['retryable'] === false);
assert($unknown['reissue_required'] === false);

$enriched = SriErrorInterpreter::enrich([
    'mensaje' => [[
        'identificador' => '45',
        'mensaje' => 'ERROR SECUENCIAL REGISTRADO',
        'informacionAdicional' => '001-001-000000126',
    ]],
]);
assert($enriched['mensaje'][0]['informacionAdicional'] === '001-001-000000126');
assert($enriched['mensaje'][0]['diagnostico']['category'] === 'sequential_registered');

echo "SRI error interpreter: OK\n";
