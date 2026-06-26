<?php

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use App\Modules\Billing\Controllers\PublicBillingController;
use App\Services\BillingApiException;

function fail(string $message): void {
    fwrite(STDERR, "[billing-public-document-errors] {$message}\n");
    exit(1);
}

function invokePrivate(object $instance, string $method, array $arguments = []): mixed {
    $reflection = new ReflectionMethod($instance, $method);

    return $reflection->invokeArgs($instance, $arguments);
}

$controller = new PublicBillingController();
$xmlUnavailable = new BillingApiException(
    'No se pudo obtener XML autorizado del facturador (404) en http://facturador:8080/api/test/v1/invoices/1506202601175968768200120010010000001176639548314/xml: XML autorizado no disponible todavia para esta clave de acceso',
    404,
    'native://billing/xml'
);

$xmlMessage = invokePrivate($controller, 'fileErrorMessage', [$xmlUnavailable, 'xml']);
$xmlCode = invokePrivate($controller, 'fileErrorCode', [$xmlUnavailable, 'xml']);
$xmlDetails = invokePrivate($controller, 'fileErrorDetails', [$xmlUnavailable, 'xml']);

if ($xmlCode !== 'BILLING_XML_NOT_AVAILABLE') {
    fail("codigo XML inesperado: {$xmlCode}");
}

foreach (['facturador:8080', '/api/test/v1/invoices', 'BILLING_XML_UPSTREAM_FAILED'] as $technicalFragment) {
    if (str_contains($xmlMessage, $technicalFragment)) {
        fail("mensaje XML expone detalle tecnico: {$technicalFragment}");
    }
}

if (!str_contains($xmlMessage, 'El XML autorizado aun no esta disponible')) {
    fail('mensaje XML no comunica disponibilidad pendiente');
}

if (($xmlDetails['retry_after_seconds'] ?? null) !== 3600 || ($xmlDetails['action'] ?? null) !== 'check_status') {
    fail('detalles XML no fijan accion check_status y retry de una hora');
}

$rideUnavailable = new BillingApiException('RIDE PDF vacio o no disponible', 404, 'native://billing/ride.pdf');
$rideMessage = invokePrivate($controller, 'fileErrorMessage', [$rideUnavailable, 'ride-pdf']);
$rideCode = invokePrivate($controller, 'fileErrorCode', [$rideUnavailable, 'ride-pdf']);
$rideDetails = invokePrivate($controller, 'fileErrorDetails', [$rideUnavailable, 'ride-pdf']);

if ($rideCode !== 'BILLING_RIDE_PDF_NOT_AVAILABLE') {
    fail("codigo RIDE inesperado: {$rideCode}");
}

if (!str_contains($rideMessage, 'El RIDE PDF aun no esta disponible')) {
    fail('mensaje RIDE no comunica disponibilidad pendiente');
}

if (($rideDetails['retry_after_seconds'] ?? null) !== 3600 || ($rideDetails['status'] ?? null) !== 'pending') {
    fail('detalles RIDE no fijan estado pendiente y retry de una hora');
}

printf("[billing-public-document-errors] OK\n");
