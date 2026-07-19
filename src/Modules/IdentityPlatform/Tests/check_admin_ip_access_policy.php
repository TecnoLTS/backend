<?php

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Core\AdminIpAccessPolicy;

$expectError = static function (string $mode, string $allowlist): void {
    if (AdminIpAccessPolicy::productionConfigurationError('production', $mode, $allowlist) === null) {
        throw new RuntimeException("unsafe production admin policy was accepted: {$mode}");
    }
};

$expectError('off', '');
$expectError('private', '10.0.0.0/8,192.168.0.0/16');
$expectError('custom', '198.51.100.10/32');
$expectError('custom', '0.0.0.0/0,2001:db8::1/128');
$expectError('custom', 'not-an-ip/32,2001:db8::1/128');

$valid = '198.51.100.10/32,2001:db8::1/128';
if (AdminIpAccessPolicy::productionConfigurationError('production', 'custom', $valid) !== null) {
    throw new RuntimeException('safe production admin policy was rejected');
}
if (!AdminIpAccessPolicy::matches('198.51.100.10', $valid, 'custom')) {
    throw new RuntimeException('allowed IPv4 admin address was rejected');
}
if (AdminIpAccessPolicy::matches('198.51.100.11', $valid, 'custom')) {
    throw new RuntimeException('unlisted IPv4 admin address was accepted');
}
if (AdminIpAccessPolicy::matches('127.0.0.1', '', 'custom')) {
    throw new RuntimeException('empty custom policy failed open');
}
if (!AdminIpAccessPolicy::matches('192.168.100.229', '', 'private')) {
    throw new RuntimeException('QA private address was rejected');
}

echo "Admin IP access policy: OK\n";
