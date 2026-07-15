<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
use KevStudios\Beacon\Doctrine\DoctrineMiddleware;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Transport\SenderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require __DIR__.'/../vendor/autoload.php';

final class DoctrineCapturingSender implements SenderInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $captured = [];

    public function send(string $endpoint, array $payloads): bool
    {
        $this->captured[$endpoint] = array_merge($this->captured[$endpoint] ?? [], $payloads);

        return true;
    }
}

$failures = 0;
function doctrineCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '  ok  ' : 'FAIL  ').$label."\n";
    if (!$condition) {
        ++$failures;
    }
}

function doctrineAll(array $values, callable $predicate): bool
{
    foreach ($values as $value) {
        if (!$predicate($value)) {
            return false;
        }
    }

    return true;
}

$sender = new DoctrineCapturingSender();
$beacon = new Beacon(new Config(resource: ['service.name' => 'doctrine-smoke']), $sender);
$requestSpan = new RequestSpanSubscriber($beacon);
$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
};
$request = Request::create('/users/42');
$requestSpan->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

$dbal = new DbalConfiguration();
$dbal->setMiddlewares([new DoctrineMiddleware($beacon, $requestSpan)]);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $dbal);
$connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
$connection->executeStatement('INSERT INTO users (email) VALUES (?)', ['secret@example.com']);
$connection->fetchOne('SELECT email FROM users WHERE id = ?', [1]);

$response = new Response('', 200);
$requestSpan->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
$requestSpan->onTerminate(new TerminateEvent($kernel, $request, $response));
$beacon->flush();

$payloads = $sender->captured['traces'] ?? [];
$spans = [];
foreach ($payloads as $payload) {
    foreach ($payload['scopes'][0]['spans'] ?? [] as $span) {
        $spans[] = $span;
    }
}
$dbSpans = array_values(array_filter($spans, static fn (array $span): bool => ($span['attributes']['beacon.span_type'] ?? null) === 'db_query'));
$root = array_values(array_filter($spans, static fn (array $span): bool => ($span['attributes']['beacon.span_type'] ?? null) === 'http_request'))[0] ?? null;

doctrineCheck('three DBAL calls become db_query spans', count($dbSpans) === 3);
doctrineCheck('request root span is captured', is_array($root));
doctrineCheck('SQL values never reach span names', !str_contains(json_encode($dbSpans, JSON_THROW_ON_ERROR), 'secret@example.com'));
doctrineCheck('numeric SQL literals are normalized', ($dbSpans[0]['name'] ?? null) === 'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
doctrineCheck('DB spans share the request trace id', $root !== null && doctrineAll($dbSpans, static fn (array $span): bool => $span['traceId'] === $root['traceId']));
doctrineCheck('DB spans are children of the HTTP span', $root !== null && doctrineAll($dbSpans, static fn (array $span): bool => $span['parentSpanId'] === $root['spanId']));
doctrineCheck('SQLite semantic attribute is present', doctrineAll($dbSpans, static fn (array $span): bool => ($span['attributes']['db.system.name'] ?? null) === 'sqlite'));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
