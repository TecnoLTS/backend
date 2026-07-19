<?php

declare(strict_types=1);

$bootstrapPath = __DIR__ . '/bootstrap_module_databases.php';
$connectionPath = __DIR__ . '/bootstrap_schema.php';
$bootstrap = file_get_contents($bootstrapPath);
$connection = file_get_contents($connectionPath);
if (!is_string($bootstrap) || !is_string($connection)) {
    fwrite(STDERR, "No se pudieron leer los scripts de bootstrap FDW.\n");
    exit(1);
}

$start = strpos($bootstrap, 'function setFdwBootstrapGucs');
$end = strpos($bootstrap, 'function fdwMappingConfig');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "No se encontro la frontera segura de creacion del mapping FDW.\n");
    exit(1);
}
$mappingBoundary = substr($bootstrap, $start, $end - $start);

$required = [
    'PDO usa prepares nativos' => 'PDO::ATTR_EMULATE_PREPARES => false',
    'scope local dentro de transaccion' => '$transactionLocal = $pdo->inTransaction()',
    'supresion temporal de parametros en statement logs' => "set_config('log_parameter_max_length', '0', %1\$s)",
    'supresion temporal de parametros en error logs' => "set_config('log_parameter_max_length_on_error', '0', %1\$s)",
    'requisito SUSET documentado antes del bind' => 'log_parameter_max_length as SUSET',
    'password cargado por set_config enlazado' => "set_config('paramascotasec.bootstrap_fdw_password', :mapping_password, %1\$s)",
    'password asignado como parametro PDO' => "bindValue(':mapping_password', (string)\$mappingConfig['password'], PDO::PARAM_STR)",
    'password leido solo desde el GUC en PostgreSQL' => "current_setting('paramascotasec.bootstrap_fdw_password', true)",
    'utility command construido dentro de PostgreSQL' => 'EXECUTE format(',
    'estado de exito no sensible' => "CASE WHEN mapping_created THEN 'ok' ELSE 'error:'",
    'error publico generico' => "throw new RuntimeException('Unable to create FDW user mapping.')",
    'excepcion de driver sin encadenar' => 'catch (Throwable) {',
    'restauracion de logging de parametros' => 'restoreFdwBootstrapParameterLogging($pdo, $parameterLogSettings, $transactionLocal)',
];

foreach ($required as $description => $snippet) {
    $haystack = $description === 'PDO usa prepares nativos' ? $connection : $mappingBoundary;
    if (!str_contains($haystack, $snippet)) {
        fwrite(STDERR, "Falta el contrato FDW seguro: {$description}.\n");
        exit(1);
    }
}

if (substr_count($mappingBoundary, "set_config('paramascotasec.bootstrap_fdw_password', '',") < 2) {
    fwrite(STDERR, "El password FDW no se limpia tanto dentro del DO como en el finally de PHP.\n");
    exit(1);
}

if (substr_count($mappingBoundary, "\$mappingConfig['password']") !== 1) {
    fwrite(STDERR, "El password FDW debe aparecer en PHP una sola vez y exclusivamente como bindValue.\n");
    exit(1);
}

$suspendPosition = strpos($mappingBoundary, 'suspendFdwBootstrapParameterLogging($pdo, $transactionLocal)');
$bindPosition = strpos($mappingBoundary, 'setFdwBootstrapGucs($pdo, $serverName, $mappingConfig, $transactionLocal)');
if ($suspendPosition === false || $bindPosition === false || $suspendPosition >= $bindPosition) {
    fwrite(STDERR, "El logging de parametros debe suspenderse antes de enlazar el password FDW.\n");
    exit(1);
}

$forbiddenPatterns = [
    'password citado por PDO' => '/\$pdo->quote\s*\([^;]*(?:mappingConfig|DB_FDW_PASSWORD|password)/i',
    'password interpolado en OPTIONS' => '/OPTIONS\s*\(\s*user\s+%s\s*,\s*password\s+%s\s*\)/i',
    'mapping creado por sprintf/exec' => '/->exec\s*\(\s*sprintf\s*\(\s*[\'\"][^\'\"]*CREATE\s+USER\s+MAPPING/is',
    'secreto interpolado en el utility command' => '/CREATE\s+USER\s+MAPPING[^;]*(?:\$mappingConfig|DB_FDW_PASSWORD)/is',
    'excepcion de driver reencadenada' => '/Unable to create FDW user mapping\.\s*[\'\"]\s*,\s*0\s*,/i',
];

foreach ($forbiddenPatterns as $description => $pattern) {
    if (preg_match($pattern, $mappingBoundary) === 1) {
        fwrite(STDERR, "Patron inseguro en bootstrap FDW: {$description}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Frontera de secreto bootstrap FDW: OK\n");
