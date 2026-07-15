<?php

declare(strict_types=1);

/**
 * Dependency-free smoke test for the Beacon PHP core (no Symfony, no PHPUnit).
 * Run: php tests/smoke.php
 */

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
use KevStudios\Beacon\Doctrine\SqlNormalizer;
use KevStudios\Beacon\Propagation\TraceContext;
use KevStudios\Beacon\Propagation\W3CPropagator;
use KevStudios\Beacon\Transport\CurlSender;
use KevStudios\Beacon\Transport\SenderInterface;

// Minimal PSR-4 autoloader for KevStudios\Beacon\ → src/.
spl_autoload_register(static function (string $class): void {
    $prefix = 'KevStudios\\Beacon\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, \strlen($prefix)));
    $file = __DIR__.'/../src/'.$relative.'.php';
    if (is_file($file)) {
        require $file;
    }
});

/** Sender that captures payloads instead of sending them. */
final class CapturingSender implements SenderInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $captured = [];

    public function send(string $endpoint, array $payloads): bool
    {
        $this->captured[$endpoint] = array_merge($this->captured[$endpoint] ?? [], $payloads);

        return true;
    }
}

final class FailingSender implements SenderInterface
{
    public int $calls = 0;

    public function send(string $endpoint, array $payloads): bool
    {
        ++$this->calls;

        return false;
    }
}

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        echo "FAIL  $label\n";
        ++$failures;
    }
}

$config = new Config(
    resource: ['service.name' => 'iautos-api', 'service.stage' => 'production'],
    applicationPath: \dirname(__DIR__),
);
$sender = new CapturingSender();
$beacon = new Beacon($config, $sender);

// 1. Error capture with sensitive attribute (must be censored).
function deepThrow(): void
{
    throw new RuntimeException('boom in payment');
}
try {
    deepThrow();
} catch (Throwable $e) {
    $beacon->captureException($e, handled: true, attributes: [
        'authorization' => 'Bearer super-secret-token',
        'order.id' => 42,
        'request' => ['credentials' => ['password' => 'clear-text']],
    ]);
}

// 2. A trace.
$beacon->captureSpans([[
    'traceId' => str_repeat('a', 32),
    'spanId' => str_repeat('b', 16),
    'parentSpanId' => null,
    'name' => 'POST /checkout',
    'startTimeUnixNano' => '1710252000000000000',
    'endTimeUnixNano' => '1710252000150000000',
    'status' => ['code' => 1],
    'attributes' => ['beacon.span_type' => 'http_request', 'request' => ['api_token' => 'trace-secret']],
    'events' => [],
]]);

// 3. A log.
$beacon->log('ERROR', 'payment failed', ['order.id' => 42, 'http' => ['authorization' => 'Bearer log-secret']]);

$beacon->flush();

echo "Errors:\n";
$error = $sender->captured['errors'][0] ?? [];
check('one error captured', \count($sender->captured['errors'] ?? []) === 1);
check('exceptionClass is RuntimeException', ($error['exceptionClass'] ?? null) === RuntimeException::class);
check('message preserved', ($error['message'] ?? null) === 'boom in payment');
check('handled flag true', ($error['handled'] ?? null) === true);
check('stacktrace non-empty', \count($error['stacktrace'] ?? []) > 0);
check('top frame has line number', ($error['stacktrace'][0]['lineNumber'] ?? 0) > 0);
check('an application frame is detected', (bool) array_filter($error['stacktrace'] ?? [], fn ($f) => $f['isApplicationFrame'] ?? false));
check('stack arguments disabled by default', array_filter($error['stacktrace'] ?? [], fn ($f) => ($f['arguments'] ?? null) !== null) === []);
check('authorization attribute censored', ($error['attributes']['authorization'] ?? null) === '[CENSORED]');
check('nested password attribute censored', ($error['attributes']['request']['credentials']['password'] ?? null) === '[CENSORED]');
check('non-sensitive attribute kept', ($error['attributes']['order.id'] ?? null) === 42);
check('resource carries service.name', ($error['resource']['service.name'] ?? null) === 'iautos-api');
check('trackingUuid present', (bool) preg_match('/^[0-9a-f-]{36}$/', $error['trackingUuid'] ?? ''));

echo "Traces:\n";
$trace = $sender->captured['traces'][0] ?? [];
check('one trace captured', \count($sender->captured['traces'] ?? []) === 1);
check('span nested under scope', ($trace['scopes'][0]['spans'][0]['name'] ?? null) === 'POST /checkout');
check('nested span token censored', ($trace['scopes'][0]['spans'][0]['attributes']['request']['api_token'] ?? null) === '[CENSORED]');

echo "Logs:\n";
$log = $sender->captured['logs'][0] ?? [];
check('one log captured', \count($sender->captured['logs'] ?? []) === 1);
check('severity mapped ERROR→17', ($log['records'][0]['severityNumber'] ?? null) === 17);
check('nested log authorization censored', ($log['records'][0]['attributes']['http']['authorization'] ?? null) === '[CENSORED]');

echo "Reliability:\n";
$disabled = new Beacon(new Config(resource: ['service.name' => 'disabled-test']), new CurlSender('', ''));
check('empty endpoint and token disable all telemetry work', !$disabled->isEnabled());
$sampledOut = new CapturingSender();
$neverSample = new Beacon(new Config(resource: ['service.name' => 'sample-test'], tracesSampleRate: 0.0), $sampledOut);
$neverSample->captureSpans([[
    'traceId' => str_repeat('0', 32), 'spanId' => str_repeat('1', 16), 'name' => 'drop',
    'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2', 'attributes' => [], 'events' => [],
]]);
$neverSample->flush();
check('trace sampling rate 0 drops every trace', ($sampledOut->captured['traces'] ?? []) === []);

$boundedOut = new CapturingSender();
$bounded = new Beacon(new Config(resource: ['service.name' => 'bounded-test'], maxBacklogItems: 2), $boundedOut);
for ($i = 1; $i <= 3; ++$i) {
    try {
        throw new RuntimeException('bounded '.$i);
    } catch (Throwable $e) {
        $bounded->captureException($e);
    }
}
$bounded->flush();
check('backlog retains only the configured maximum', \count($boundedOut->captured['errors'] ?? []) === 2);

$failingOut = new FailingSender();
$retrying = new Beacon(new Config(resource: ['service.name' => 'retry-test']), $failingOut);
$retrying->log('ERROR', 'keep me');
$retrying->flush();
$retrying->flush();
check('failed transport keeps the buffer for the next flush', $failingOut->calls === 2);

$context = new TraceContext(
    '0af7651916cd43dd8448eb211c80319c',
    'b7ad6b7169203331',
    true,
    'vendor=value',
    'tenant=acme',
);
$headers = W3CPropagator::inject($context, ['accept' => 'application/json']);
$extracted = W3CPropagator::extract($headers);
check('W3C traceparent is injected', ($headers['traceparent'] ?? null) === '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
check('W3C traceparent/tracestate/baggage round-trip', $extracted == $context);
check('all-zero W3C trace id is rejected', W3CPropagator::parseTraceparent('00-00000000000000000000000000000000-b7ad6b7169203331-01') === null);

echo "Doctrine SQL normalization:\n";
$normalizedSql = SqlNormalizer::normalize("SELECT *\nFROM users WHERE email = 'person@example.com' AND id = 42 AND role IN ('admin', 'member')");
check('SQL literal values are removed', !str_contains($normalizedSql, 'person@example.com') && !str_contains($normalizedSql, '42') && !str_contains($normalizedSql, 'admin'));
check('SQL placeholders collapse to a stable group', $normalizedSql === 'SELECT * FROM users WHERE email = ? AND id = ? AND role IN (?)');
check('SQL operation is extracted', SqlNormalizer::operation($normalizedSql) === 'SELECT');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
