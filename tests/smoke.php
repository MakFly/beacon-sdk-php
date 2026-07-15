<?php

declare(strict_types=1);

/**
 * Dependency-free smoke test for the Beacon PHP core (no Symfony, no PHPUnit).
 * Run: php tests/smoke.php
 */

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
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

    public function send(string $endpoint, array $payloads): void
    {
        $this->captured[$endpoint] = array_merge($this->captured[$endpoint] ?? [], $payloads);
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
    'attributes' => ['beacon.span_type' => 'http_request'],
    'events' => [],
]]);

// 3. A log.
$beacon->log('ERROR', 'payment failed', ['order.id' => 42]);

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
check('non-sensitive attribute kept', ($error['attributes']['order.id'] ?? null) === 42);
check('resource carries service.name', ($error['resource']['service.name'] ?? null) === 'iautos-api');
check('trackingUuid present', (bool) preg_match('/^[0-9a-f-]{36}$/', $error['trackingUuid'] ?? ''));

echo "Traces:\n";
$trace = $sender->captured['traces'][0] ?? [];
check('one trace captured', \count($sender->captured['traces'] ?? []) === 1);
check('span nested under scope', ($trace['scopes'][0]['spans'][0]['name'] ?? null) === 'POST /checkout');

echo "Logs:\n";
$log = $sender->captured['logs'][0] ?? [];
check('one log captured', \count($sender->captured['logs'] ?? []) === 1);
check('severity mapped ERROR→17', ($log['records'][0]['severityNumber'] ?? null) === 17);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
