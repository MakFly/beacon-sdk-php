<?php

declare(strict_types=1);

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
use KevStudios\Beacon\Http\Psr18TracingClient;
use KevStudios\Beacon\Protocol;
use KevStudios\Beacon\Symfony\DependencyInjection\BeaconExtension;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Transport\SenderInterface;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require __DIR__.'/../vendor/autoload.php';

final class HttpClientCapturingSender implements SenderInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $captured = [];

    public function send(string $endpoint, array $payloads): bool
    {
        $this->captured[$endpoint] = array_merge($this->captured[$endpoint] ?? [], $payloads);

        return true;
    }
}

final class StubPsr18Client implements ClientInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ResponseInterface $response = null,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->response ?? new Response(200);
    }
}

final class StubPsr18Exception extends \RuntimeException implements ClientExceptionInterface
{
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok  ' : 'FAIL  ').$label."\n";
    $failures += $condition ? 0 : 1;
};
$kernel = new class implements HttpKernelInterface {
    public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
    {
        return new SymfonyResponse();
    }
};

$sender = new HttpClientCapturingSender();
$beacon = new Beacon(new Config(resource: ['service.name' => 'psr18-smoke']), $sender);
$requestSpan = new RequestSpanSubscriber($beacon);
$rootRequest = SymfonyRequest::create('/api/v1/search', 'POST');
$requestSpan->onRequest(new RequestEvent($kernel, $rootRequest, HttpKernelInterface::MAIN_REQUEST));

$inner = new StubPsr18Client(new Response(201, [], null, '1.1'));
$client = new Psr18TracingClient($inner, $beacon, $requestSpan);
$response = $client->sendRequest(new Request(
    'POST',
    'http://meilisearch:7700/indexes/private-index/search?apiKey=super-secret',
    ['Authorization' => 'Bearer super-secret'],
    'private search body',
));

$rootResponse = new SymfonyResponse('', 200);
$requestSpan->onResponse(new ResponseEvent($kernel, $rootRequest, HttpKernelInterface::MAIN_REQUEST, $rootResponse));
$requestSpan->onTerminate(new TerminateEvent($kernel, $rootRequest, $rootResponse));
$beacon->flush();

$spans = flattenHttpClientSpans($sender);
$root = firstHttpClientSpan($spans, Protocol::SPAN_HTTP_REQUEST);
$child = firstHttpClientSpan($spans, Protocol::SPAN_HTTP_CLIENT);
$serializedChild = json_encode($child, JSON_THROW_ON_ERROR);

$check('decorated client preserves the response', $response->getStatusCode() === 201 && $inner->calls === 1);
$check('PSR-18 call becomes one http_client span', $child !== null && count(array_filter($spans, static fn (array $span): bool => ($span['attributes'][Protocol::ATTR_SPAN_TYPE] ?? null) === Protocol::SPAN_HTTP_CLIENT)) === 1);
$check('HTTP client span is attached to the request root', $root !== null && $child !== null && $child['traceId'] === $root['traceId'] && $child['parentSpanId'] === $root['spanId']);
$check('span uses a low-cardinality Meilisearch name', ($child['name'] ?? null) === 'POST meilisearch');
$check('safe HTTP semantics are recorded', ($child['attributes']['http.response.status_code'] ?? null) === 201 && ($child['attributes']['server.port'] ?? null) === 7700 && ($child['attributes']['network.protocol.version'] ?? null) === '1.1');
$check('request secrets never enter the span', !str_contains($serializedChild, 'private-index') && !str_contains($serializedChild, 'super-secret') && !str_contains($serializedChild, 'private search body'));

$passThroughSender = new HttpClientCapturingSender();
$passThroughBeacon = new Beacon(new Config(resource: []), $passThroughSender);
$passThroughRoot = new RequestSpanSubscriber($passThroughBeacon);
$passThroughInner = new StubPsr18Client(new Response(204));
$passThroughClient = new Psr18TracingClient($passThroughInner, $passThroughBeacon, $passThroughRoot);
$passThroughClient->sendRequest(new Request('GET', 'https://example.test/private'));
$passThroughBeacon->flush();
$check('calls outside an active request pass through without spans', $passThroughInner->calls === 1 && flattenHttpClientSpans($passThroughSender) === []);

$errorSender = new HttpClientCapturingSender();
$errorBeacon = new Beacon(new Config(resource: []), $errorSender);
$errorRoot = new RequestSpanSubscriber($errorBeacon);
$errorRequest = SymfonyRequest::create('/failure', 'GET');
$errorRoot->onRequest(new RequestEvent($kernel, $errorRequest, HttpKernelInterface::MAIN_REQUEST));
$errorClient = new Psr18TracingClient(new StubPsr18Client(error: new StubPsr18Exception('secret failure')), $errorBeacon, $errorRoot);
$thrown = false;
try {
    $errorClient->sendRequest(new Request('GET', 'https://search.internal/private'));
} catch (StubPsr18Exception) {
    $thrown = true;
}
$errorResponse = new SymfonyResponse('', 500);
$errorRoot->onResponse(new ResponseEvent($kernel, $errorRequest, HttpKernelInterface::MAIN_REQUEST, $errorResponse));
$errorRoot->onTerminate(new TerminateEvent($kernel, $errorRequest, $errorResponse));
$errorBeacon->flush();
$errorSpan = firstHttpClientSpan(flattenHttpClientSpans($errorSender), Protocol::SPAN_HTTP_CLIENT);
$check('client exceptions are rethrown and recorded as errors', $thrown && ($errorSpan['status']['code'] ?? null) === Protocol::STATUS_ERROR && ($errorSpan['attributes']['error.type'] ?? null) === StubPsr18Exception::class);
$check('exception messages are not captured', !str_contains(json_encode($errorSpan, JSON_THROW_ON_ERROR), 'secret failure'));

$container = new ContainerBuilder();
$container->setParameter('kernel.environment', 'test');
$container->setParameter('kernel.project_dir', __DIR__);
$container->setDefinition(Psr18Client::class, (new Definition(Psr18Client::class))->setPublic(true));
(new BeaconExtension())->load([[]], $container);
$decorator = $container->getDefinition(Psr18TracingClient::class)->getDecoratedService();
$check('Symfony bundle decorates the concrete PSR-18 service automatically', ($decorator[0] ?? null) === Psr18Client::class);
$container->compile();
$check('compiled Symfony container resolves the traced PSR-18 client', $container->get(Psr18Client::class) instanceof Psr18TracingClient);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);

/** @return list<array<string, mixed>> */
function flattenHttpClientSpans(HttpClientCapturingSender $sender): array
{
    $spans = [];
    foreach ($sender->captured['traces'] ?? [] as $payload) {
        foreach ($payload['scopes'][0]['spans'] ?? [] as $span) {
            $spans[] = $span;
        }
    }

    return $spans;
}

/** @param list<array<string, mixed>> $spans @return array<string, mixed>|null */
function firstHttpClientSpan(array $spans, string $type): ?array
{
    foreach ($spans as $span) {
        if (($span['attributes'][Protocol::ATTR_SPAN_TYPE] ?? null) === $type) {
            return $span;
        }
    }

    return null;
}
