<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\Mailer\Application\MailDeliveryResult;
use App\Modules\Mailer\Application\MailPayloadSanitizer;
use App\Modules\Mailer\Application\MailerOutboxProcessor;
use App\Modules\Mailer\Application\MailerRetryPolicy;
use App\Modules\Mailer\Application\MailTransportException;
use App\Modules\Mailer\Application\Ports\MailerOutboxStore;
use App\Modules\Mailer\Application\Ports\MailTransport;
use App\Modules\Mailer\Application\QueuedMailMessage;

$root = dirname(__DIR__, 4);
$workspace = dirname($root);
$repositorySource = (string)file_get_contents($root . '/src/Modules/Mailer/Infrastructure/Persistence/EmailOutboxRepository.php');
$transportSource = (string)file_get_contents($root . '/src/Modules/Mailer/Infrastructure/Transport/SmtpMailTransport.php');
$serviceSource = (string)file_get_contents($root . '/src/Services/MailService.php');
$workerSource = (string)file_get_contents($root . '/scripts/process_mailer_outbox.php');
$schemaSource = (string)file_get_contents($root . '/scripts/bootstrap_module_databases.php');
$composeSource = (string)file_get_contents($root . '/docker-compose.yml');
$iacSource = (string)file_get_contents($workspace . '/infra/production-ha/kubernetes/templates/application-workloads.yaml.tmpl');
$networkSource = (string)file_get_contents($workspace . '/infra/production-ha/kubernetes/templates/network-policies.yaml.tmpl');
$rlsSource = (string)file_get_contents($workspace . '/basesdedatos/scripts/tenant-isolation.sh');
$healthAssignmentStart = strpos($workerSource, '$healthy =');
$healthAssignmentEnd = $healthAssignmentStart === false
    ? false
    : strpos($workerSource, '$degraded =', $healthAssignmentStart);
$healthAssignment = $healthAssignmentStart !== false && $healthAssignmentEnd !== false
    ? substr($workerSource, $healthAssignmentStart, $healthAssignmentEnd - $healthAssignmentStart)
    : '';

$sendOnly = substr($serviceSource, strpos($serviceSource, 'public static function send('), strpos($serviceSource, 'public static function sendWithAttachment(') - strpos($serviceSource, 'public static function send('));
$checks = [
    'plain/html request path only enqueues durable payloads' => !str_contains($sendOnly, 'SmtpMailTransport')
        && !str_contains($sendOnly, 'mail(')
        && str_contains($sendOnly, 'self::enqueue('),
    'attachments are synchronous audit-only without binary persistence' => str_contains($serviceSource, 'binary_persisted')
        && str_contains($serviceSource, 'createAttachmentAudit')
        && str_contains($serviceSource, 'completeAttachmentAudit'),
    'idempotency is tenant scoped and fingerprint guarded' => str_contains($schemaSource, 'EmailOutbox_tenant_idempotency_uidx')
        && str_contains($repositorySource, 'ON CONFLICT (tenant_id, idempotency_key) DO NOTHING')
        && str_contains($repositorySource, 'hash_equals'),
    'claims are tenant fair with skip-locked leases' => str_contains($repositorySource, 'due_tenants')
        && str_contains($repositorySource, 'CROSS JOIN LATERAL')
        && str_contains($repositorySource, 'FOR UPDATE SKIP LOCKED')
        && str_contains($repositorySource, 'LEASE_RECOVERED')
        && str_contains($repositorySource, 'lock_token'),
    'retry, expiry and dead-letter are explicit' => str_contains($repositorySource, "'dead_letter'")
        && str_contains($repositorySource, 'expires_at <= NOW()')
        && str_contains($repositorySource, 'max_attempts'),
    'manual requeue is audited with actor and reason' => str_contains($repositorySource, "'manual_requeue'")
        && str_contains($repositorySource, 'requeue_count = requeue_count + 1')
        && str_contains($workerSource, "--tenant, --actor and --reason"),
    'dead-letter acknowledgement preserves outcome and is audited' => str_contains($repositorySource, 'acknowledgeDeadLetter')
        && str_contains($repositorySource, "'manual_acknowledge'")
        && str_contains($repositorySource, "'delivery_outcome_preserved' => true")
        && str_contains($schemaSource, 'EmailOutbox_resolution_check')
        && str_contains($workerSource, 'acknowledge-id'),
    'metrics separate availability from unresolved DLQ degradation' => str_contains($workerSource, 'paramascotasec_mailer_outbox_')
        && str_contains($workerSource, 'MAILER_OUTBOX_HEALTH_MAX_DUE_AGE_SECONDS')
        && str_contains($workerSource, 'MAILER_OUTBOX_HEALTH_MAX_DEAD_LETTER')
        && str_contains($workerSource, "'degraded' => \$degraded")
        && $healthAssignment !== ''
        && !str_contains($healthAssignment, 'dead_letter'),
    'worker logs omit exception messages and payload' => !str_contains(substr($workerSource, strrpos($workerSource, '} catch (Throwable')), '$exception->getMessage()')
        && !str_contains($workerSource, "'recipient_email' =>")
        && !str_contains($workerSource, "'body' =>"),
    'SMTP message IDs use a public sender domain instead of the container hostname' => str_contains(
        $transportSource,
        '$mail->Hostname = $this->messageIdDomain($fromAddress)'
    ) && str_contains($transportSource, "MAIL_MESSAGE_ID_DOMAIN"),
    'compose worker receives only Mailer DB and SMTP surfaces' => str_contains($composeSource, 'mailer-worker:')
        && str_contains($composeSource, 'DB_WORKER_USERNAME_MAILER_SERVICE:')
        && str_contains($composeSource, 'SMTP_PASS:')
        && !preg_match('/mailer-worker:[\s\S]{0,3800}(JWT_SECRET|BILLING_API_KEY|EDGE_BACKEND_PROXY_TOKEN|OBJECT_STORAGE_ACCESS_KEY):/', $composeSource),
    'production CronJob uses dedicated minimal secrets' => str_contains($iacSource, 'name: mailer-outbox')
        && str_contains($iacSource, 'db-mailer-worker-password')
        && str_contains($iacSource, 'process_mailer_outbox.php')
        && !preg_match('/name: mailer-outbox[\s\S]{0,3200}(db-billing-worker-password|billing-outbox-api-key|jwt-secret)/', $iacSource),
    'production network permits only PostgreSQL and SMTP TLS' => str_contains($networkSource, 'metadata: {name: mailer-worker}')
        && str_contains($networkSource, 'app.kubernetes.io/name: mailer-outbox')
        && str_contains($networkSource, 'port: 465')
        && str_contains($networkSource, 'port: 587')
        && str_contains($networkSource, 'port: 6432'),
    'RLS defines a dedicated Mailer worker allowlist' => str_contains($rlsSource, 'DB_WORKER_USERNAME_MAILER_SERVICE')
        && str_contains($rlsSource, 'tenant_scope_mailer_worker')
        && str_contains($rlsSource, "ARRAY['EmailOutbox', 'EmailDeliveryLog']"),
];

$sanitized = MailPayloadSanitizer::metadata([
    'module' => 'test',
    'password' => 'must-not-persist',
    'nested' => ['api_key' => 'must-not-persist', 'safe' => 'yes'],
]);
$checks['metadata strips credential-like fields recursively'] = !isset($sanitized['password'])
    && !isset($sanitized['nested']['api_key'])
    && ($sanitized['nested']['safe'] ?? null) === 'yes';
$checks['error sanitizer redacts PII and bearer values'] = !str_contains(
    MailPayloadSanitizer::error('user@example.com Authorization: Bearer abc123'),
    'user@example.com'
) && !str_contains(MailPayloadSanitizer::error('Authorization=abc123'), 'abc123');

$first = QueuedMailMessage::create(
    'tenant-a', 'User@Example.com', 'Subject', 'plain', 'body', null,
    null, null, ['safe' => true], null, 'event:123', 3, 600, 1024
);
$second = QueuedMailMessage::create(
    'tenant-a', 'user@example.com', 'Subject', 'plain', 'body', null,
    null, null, ['safe' => true], null, 'event:123', 3, 600, 1024
);
$checks['explicit idempotency builds deterministic tenant identity'] = $first->id === $second->id
    && $first->payloadFingerprint === $second->payloadFingerprint;
foreach ([
    fn() => QueuedMailMessage::create('', 'a@example.com', 'x', 'plain', 'body'),
    fn() => QueuedMailMessage::create('tenant-a', 'invalid', 'x', 'plain', 'body'),
    fn() => QueuedMailMessage::create('tenant-a', 'a@example.com', 'x', 'plain', str_repeat('x', 2048), null, null, null, [], null, null, 3, 600, 1024),
] as $index => $invalidFactory) {
    try {
        $invalidFactory();
        $checks['payload limit rejects invalid case ' . $index] = false;
    } catch (InvalidArgumentException) {
        $checks['payload limit rejects invalid case ' . $index] = true;
    }
}

final class FakeMailerOutbox implements MailerOutboxStore
{
    public int $attempts = 0;
    public string $state = 'pending';
    public bool $transportSucceeds = false;
    public function enqueue(QueuedMailMessage $message): array { return ['accepted' => true]; }
    public function claimFairBatch(int $limit, int $perTenant, int $leaseSeconds, string $workerId): array
    {
        if (!in_array($this->state, ['pending', 'retry'], true)) {
            return [];
        }
        $this->attempts++;
        $this->state = 'processing';
        return [[
            'id' => 'mail-test', 'tenant_id' => 'tenant-a', 'recipient_email' => 'a@example.com',
            'subject' => 'subject', 'plain_body' => 'body', 'html_body' => null,
            'message_format' => 'plain', 'attempts' => $this->attempts,
            'max_attempts' => 3, 'lock_token' => 'lease-' . $this->attempts,
        ]];
    }
    public function markDelivered(array $claim, ?string $providerMessageId, string $transport): void { $this->state = 'sent'; }
    public function markFailed(array $claim, string $errorCode, string $errorMessage, MailerRetryPolicy $retryPolicy): string
    {
        return $this->state = $this->attempts >= 3 ? 'dead_letter' : 'retry';
    }
}
final class FakeMailTransport implements MailTransport
{
    public function __construct(private readonly FakeMailerOutbox $store) {}
    public function deliver(array $message): MailDeliveryResult
    {
        if (!$this->store->transportSucceeds) {
            throw new MailTransportException('temporary SMTP failure', 'SMTP_TEMPORARY');
        }
        return new MailDeliveryResult('smtp', 'provider-id');
    }
}

$store = new FakeMailerOutbox();
$processor = new MailerOutboxProcessor($store, new FakeMailTransport($store), new MailerRetryPolicy(10, 300, 20));
$processor->process(1, 1, 30, 'worker-a', 5);
$processor->process(1, 1, 30, 'worker-b', 5);
$third = $processor->process(1, 1, 30, 'worker-c', 5);
$checks['processor retries then reaches bounded DLQ'] = $store->state === 'dead_letter'
    && ($third['dead_letter'] ?? 0) === 1;
$store->state = 'retry';
$store->attempts = 0;
$store->transportSucceeds = true;
$success = $processor->process(1, 1, 30, 'worker-d', 5);
$checks['processor commits successful delivery'] = $store->state === 'sent' && ($success['sent'] ?? 0) === 1;

$retry = new MailerRetryPolicy(10, 300, 20);
$checks['retry jitter is deterministic and bounded'] = $retry->delaySeconds(2, 'mail-a') === $retry->delaySeconds(2, 'mail-a')
    && $retry->delaySeconds(1, 'mail-a') >= 1
    && $retry->delaySeconds(20, 'mail-a') <= 300;

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Mailer durable outbox contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'Mailer durable outbox: OK (' . count($checks) . " assertions)\n";
