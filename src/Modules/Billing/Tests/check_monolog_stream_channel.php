<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use BillingService\Shared\Infrastructure\Logging\MonologLogger;

function billingLogHandlerUrl(MonologLogger $logger): ?string
{
    $property = new ReflectionProperty($logger, 'logger');
    $property->setAccessible(true);
    $monolog = $property->getValue($logger);
    $handler = $monolog->getHandlers()[0] ?? null;

    return is_object($handler) && method_exists($handler, 'getUrl')
        ? $handler->getUrl()
        : null;
}

$previousChannel = $_ENV['LOG_CHANNEL'] ?? null;
$previousPath = $_ENV['LOG_PATH'] ?? null;
unset($_ENV['LOG_PATH']);

$_ENV['LOG_CHANNEL'] = 'stderr';
$stderr = billingLogHandlerUrl(new MonologLogger('billing-stderr-contract'));
$_ENV['LOG_CHANNEL'] = 'stdout';
$stdout = billingLogHandlerUrl(new MonologLogger('billing-stdout-contract'));

if ($previousChannel === null) {
    unset($_ENV['LOG_CHANNEL']);
} else {
    $_ENV['LOG_CHANNEL'] = $previousChannel;
}
if ($previousPath === null) {
    unset($_ENV['LOG_PATH']);
} else {
    $_ENV['LOG_PATH'] = $previousPath;
}

if ($stderr !== 'php://stderr' || $stdout !== 'php://stdout') {
    fwrite(STDERR, sprintf(
        "Billing log stream contract failed: stderr=%s stdout=%s\n",
        $stderr ?? '<null>',
        $stdout ?? '<null>'
    ));
    exit(1);
}

echo "Billing log stream contract: OK stderr/stdout\n";
