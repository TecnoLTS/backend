<?php

declare(strict_types=1);

$harness = __DIR__ . '/run_sustained_mixed_load.sh';
$source = file_get_contents($harness);
if ($source === false) {
    fwrite(STDERR, "No se pudo leer el harness de carga.\n");
    exit(1);
}

$collector = __DIR__ . '/collect_runtime_metrics.sh';
$collectorSource = file_get_contents($collector);
if ($collectorSource === false) {
    fwrite(STDERR, "No se pudo leer el recolector de metricas.\n");
    exit(1);
}

$markers = [
    'anchor' => 'next_start_ms=$((started_epoch_ms + stagger_ms))',
    'wait' => 'wait_ms=$((next_start_ms - now_ms))',
    'request' => 'request_started_ms="$(date +%s%3N)"',
    'advance' => 'next_start_ms=$((request_started_ms + REQUEST_INTERVAL_MS))',
    'skip' => '"missed_slot_policy" => "skip_without_catch_up"',
    'manifest' => '"mode" => "absolute_start_cadence"',
];
$positions = [];
foreach ($markers as $name => $marker) {
    $position = strpos($source, $marker);
    if ($position === false) {
        fwrite(STDERR, "Falta contrato de cadencia absoluta: {$name}.\n");
        exit(1);
    }
    $positions[$name] = $position;
}

if (!($positions['anchor'] < $positions['wait']
    && $positions['wait'] < $positions['request']
    && $positions['request'] < $positions['advance']
    && $positions['advance'] < $positions['skip'])) {
    fwrite(STDERR, "La cadencia no espera antes del inicio o no avanza despues de medir.\n");
    exit(1);
}
if (str_contains($source, 'sleep "${request_interval_seconds}"')) {
    fwrite(STDERR, "El harness volvio a agregar el intervalo completo despues de responder.\n");
    exit(1);
}

$observerMarkers = [
    'manifest_v4' => '"version" => 4',
    'nice_required' => 'docker find nice php',
    'nice_default' => 'local -a collector_command=(nice -n 10)',
    'ionice_probe' => 'ionice -c 3 true',
    'ionice_idle' => 'collector_command=(ionice -c 3 nice -n 10)',
    'prioritized_invocation' => '"${collector_command[@]}" "${SCRIPT_DIR}/collect_runtime_metrics.sh"',
    'load_profile' => 'COLLECTION_PROFILE=load OUTPUT_FILE="${output}"',
    'collector_timeout_forwarded' => 'TIMEOUT_SECONDS="${TIMEOUT_SECONDS}"',
    'profile_gate' => 'paramascotas_collector_profile{profile="load"}',
    'profile_manifest' => '"collection_profile" => "load"',
];
foreach ($observerMarkers as $name => $marker) {
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "Falta contrato de prioridad baja del observador: {$name}.\n");
        exit(1);
    }
}

$collectorMarkers = [
    'method_default' => 'local method="${4:-GET}"',
    'head_option' => 'HEAD) curl_method_args=(--head) ;;',
    'storefront_head' => '"storefront_home|/|HEAD|/"',
    'backend_live_probe' => '"api_live|/${TENANT_SLUG}/api/livez|GET|/${TENANT_SLUG}/api/livez"',
    'method_forwarding' => '"${probe_method:-GET}"',
    'stable_success_labels' => 'paramascotas_http_probe_success{name="%s",path="%s"}',
    'bounded_catalog_probe' => 'api/products?page_size=1',
    'batched_container_metrics' => 'emit_container_metrics',
    'profile_default' => 'COLLECTION_PROFILE="${COLLECTION_PROFILE:-full}"',
    'profile_metric' => 'paramascotas_collector_profile{profile="%s"}',
    'outbox_full_only' => 'if [[ "${COLLECTION_PROFILE}" == "full" ]]; then',
    'fpm_netns' => 'hard_timeout nsenter -t "${backend_http_pid}" -n',
    'hard_timeout' => 'timeout --kill-after=2s "${TIMEOUT_SECONDS}s"',
    'postgres_netns' => 'hard_timeout nsenter -t "${database_pid}" -n',
    'postgres_pid_from_inspect' => '$1 == "/basesdedatos" {print $7; exit}',
    'postgres_secret_from_proc' => 'done <"/proc/${database_pid}/environ"',
    'postgres_pgpass' => 'PGPASSFILE="${passfile}"',
    'postgres_pgpass_mode' => 'chmod 600 "${passfile}"',
    'postgres_noninteractive' => 'psql -XAtq -w -h 127.0.0.1',
    'postgres_fallback' => 'method="docker_exec"',
    'postgres_retry_bound' => 'for fallback_attempt in 1 2; do',
    'postgres_transport_classifier' => 'postgres_failure_is_transport',
    'postgres_attempt_metric' => 'paramascotas_postgres_scrape_attempts',
    'postgres_transport_metric' => 'paramascotas_postgres_scrape_transport{method="%s"}',
];
foreach ($collectorMarkers as $name => $marker) {
    if (!str_contains($collectorSource, $marker)) {
        fwrite(STDERR, "Falta contrato HEAD/labels del recolector: {$name}.\n");
        exit(1);
    }
}
$inspectWait = strpos($collectorSource, 'wait "${inspect_pid}"');
$postgresStart = strpos($collectorSource, 'collect_postgres_metrics_source "${inspect_file}"');
if ($inspectWait === false || $postgresStart === false || $inspectWait >= $postgresStart) {
    fwrite(STDERR, "PostgreSQL debe reutilizar el inspect batched antes de abrir su transporte.\n");
    exit(1);
}
if (str_contains($collectorSource, 'collect_postgres_metrics_source >"${postgres_metrics_file}" 2>/dev/null')) {
    fwrite(STDERR, "El colector volvio a descartar el diagnostico PostgreSQL.\n");
    exit(1);
}
if (substr_count($collectorSource, 'run_postgres_docker_attempt "${attempt_output}" "${attempt_stderr}"') !== 1) {
    fwrite(STDERR, "El fallback Docker debe vivir en un unico loop acotado.\n");
    exit(1);
}
if (substr_count($collectorSource, '|HEAD|') !== 1) {
    fwrite(STDERR, "Solo la sonda storefront_home debe usar HEAD.\n");
    exit(1);
}
if (str_contains($collectorSource, 'api_ready|')
    || str_contains($collectorSource, '/api/readyz|GET')) {
    fwrite(STDERR, "El observador recurrente no debe ejecutar readiness profunda.\n");
    exit(1);
}

// Casos focales de la aritmetica declarada por el harness. Una respuesta
// rapida espera solo el remanente hasta el siguiente inicio; una respuesta
// lenta vence ese slot y continua sin ejecutar solicitudes de recuperacion.
$intervalMs = 1000;
$fastStartMs = 10_000;
$fastFinishMs = 10_240;
$fastNextStartMs = $fastStartMs + $intervalMs;
$fastRemainingMs = max(0, $fastNextStartMs - $fastFinishMs);
if ($fastNextStartMs !== 11_000 || $fastRemainingMs !== 760) {
    fwrite(STDERR, "La cadencia rapida agrego think-time o perdio su ancla de inicio.\n");
    exit(1);
}
$slowStartMs = 20_000;
$slowFinishMs = 21_350;
$slowNextStartMs = $slowStartMs + $intervalMs;
$slowRemainingMs = max(0, $slowNextStartMs - $slowFinishMs);
if ($slowNextStartMs !== 21_000 || $slowRemainingMs !== 0) {
    fwrite(STDERR, "La cadencia lenta intento recuperar slots o agrego una espera posterior.\n");
    exit(1);
}

$workers = 8;
$startsPerSecond = [];
for ($worker = 0; $worker < $workers; $worker++) {
    $phaseMs = intdiv($worker * $intervalMs, $workers);
    for ($cycle = 0; $cycle < 4; $cycle++) {
        $second = intdiv($phaseMs + $cycle * $intervalMs, 1000);
        $startsPerSecond[$second] = ($startsPerSecond[$second] ?? 0) + 1;
    }
}
if (max($startsPerSecond) !== 8 || max($startsPerSecond) > 9) {
    fwrite(STDERR, "El escalonamiento canonico excede el tope de inicios por segundo.\n");
    exit(1);
}

echo "Sustained load pacing and low-impact observer contract: OK\n";
