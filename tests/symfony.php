<?php

declare(strict_types=1);

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
use KevStudios\Beacon\Symfony\EventSubscriber\ExceptionSubscriber;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Symfony\DependencyInjection\BeaconExtension;
use KevStudios\Beacon\Symfony\ExceptionCaptureRegistry;
use KevStudios\Beacon\Symfony\Monolog\ExceptionMonologHandler;
use KevStudios\Beacon\Symfony\UserContextProviderInterface;
use KevStudios\Beacon\TelemetryContext;
use KevStudios\Beacon\Transport\SenderInterface;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require __DIR__.'/../vendor/autoload.php';

final class SymfonyCapturingSender implements SenderInterface
{
    /** @var array<string,list<array<string,mixed>>> */
    public array $captured = [];

    public function send(string $endpoint, array $payloads): bool
    {
        $this->captured[$endpoint] = array_merge($this->captured[$endpoint] ?? [], $payloads);

        return true;
    }
}

final class SymfonyTestTokenStorage
{
    public function getToken(): object
    {
        return new class {
            public function getUser(): object
            {
                return new class {
                    public function getUserIdentifier(): string
                    {
                        return 'private@example.test';
                    }
                };
            }
        };
    }
}

$failures = 0;
function symfonyCheck(string $label, bool $condition): void
{
    global $failures;
    echo($condition ? '  ok  ' : 'FAIL  ').$label."\n";
    $failures += $condition ? 0 : 1;
}

$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
};

$run = static function (int $statusCode) use ($kernel): SymfonyCapturingSender {
    $sender = new SymfonyCapturingSender();
    $context = new TelemetryContext();
    $beacon = new Beacon(new Config(resource: ['service.name' => 'symfony-test'], tracesSampleRate: 0.0), $sender, $context);
    $requestSpan = new RequestSpanSubscriber($beacon, context: $context);
    $exceptions = new ExceptionSubscriber($beacon);
    $request = Request::create('/failure', 'GET');
    $response = new Response('', $statusCode);

    $requestSpan->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    $exceptions->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('failure')));
    $exceptions->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
    $requestSpan->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
    $exceptions->onTerminate(new TerminateEvent($kernel, $request, $response));
    $requestSpan->onTerminate(new TerminateEvent($kernel, $request, $response));

    return $sender;
};

$handled = $run(400);
$handledError = $handled->captured['errors'][0] ?? [];
symfonyCheck('HTTP 400 exception is captured as handled', ($handledError['handled'] ?? null) === true);
symfonyCheck('HTTP 400 exception is correlated to its request trace', \is_string($handledError['traceId'] ?? null) && \is_string($handledError['spanId'] ?? null));
symfonyCheck('HTTP 400 does not bypass trace sampling', ($handled->captured['traces'] ?? []) === []);

$failed = $run(500);
$failedError = $failed->captured['errors'][0] ?? [];
$failedRoot = $failed->captured['traces'][0]['scopes'][0]['spans'][0] ?? [];
symfonyCheck('HTTP 500 exception is captured as unhandled', ($failedError['handled'] ?? null) === false);
symfonyCheck('HTTP 500 error forces root trace capture', \count($failed->captured['traces'] ?? []) === 1);
symfonyCheck('forced HTTP 500 root span has error status', ($failedRoot['status']['code'] ?? null) === 2);
symfonyCheck('error and forced trace share the same trace id', ($failedError['traceId'] ?? null) === ($failedRoot['traceId'] ?? null));

$fallbackSender = new SymfonyCapturingSender();
$fallbackContext = new TelemetryContext();
$fallbackBeacon = new Beacon(new Config(resource: ['service.name' => 'symfony-test'], tracesSampleRate: 0.0), $fallbackSender, $fallbackContext);
$fallbackRequestSpan = new RequestSpanSubscriber($fallbackBeacon, context: $fallbackContext);
$fallbackExceptions = new ExceptionSubscriber($fallbackBeacon);
$fallbackRequest = Request::create('/aborted', 'GET');
$fallbackResponse = new Response('', 500);
$fallbackRequestSpan->onRequest(new RequestEvent($kernel, $fallbackRequest, HttpKernelInterface::MAIN_REQUEST));
$fallbackExceptions->onException(new ExceptionEvent($kernel, $fallbackRequest, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('aborted')));
$fallbackExceptions->onTerminate(new TerminateEvent($kernel, $fallbackRequest, $fallbackResponse));
$fallbackRequestSpan->onTerminate(new TerminateEvent($kernel, $fallbackRequest, $fallbackResponse));
$fallbackError = $fallbackSender->captured['errors'][0] ?? [];
$fallbackRoot = $fallbackSender->captured['traces'][0]['scopes'][0]['spans'][0] ?? [];
symfonyCheck('no-response fallback remains correlated and forces its root trace', ($fallbackError['traceId'] ?? null) === ($fallbackRoot['traceId'] ?? null));

$loggedSender = new SymfonyCapturingSender();
$loggedBeacon = new Beacon(new Config(resource: ['service.name' => 'symfony-test']), $loggedSender);
$loggedRegistry = new ExceptionCaptureRegistry();
$loggedHandler = new ExceptionMonologHandler($loggedBeacon, $loggedRegistry);
$logger = new Logger('messenger');
$logger->pushHandler($loggedHandler);
$loggedThrowable = new RuntimeException('worker failure');
$logger->error('Message handling failed', ['nested' => ['exception' => $loggedThrowable], 'job_id' => 42]);
$logger->error('Message handling failed again', ['exception' => $loggedThrowable]);
$loggedError = $loggedSender->captured['errors'][0] ?? [];
symfonyCheck('Monolog ERROR Throwable is captured as a handled issue', ($loggedError['handled'] ?? null) === true);
symfonyCheck('Monolog exception is flushed immediately for workers', \count($loggedSender->captured['errors'] ?? []) === 1);
symfonyCheck('the same logged Throwable is captured only once', \count($loggedSender->captured['errors'] ?? []) === 1);
symfonyCheck('Monolog issue keeps useful scalar context', ($loggedError['attributes']['context.job_id'] ?? null) === 42);

$dedupeSender = new SymfonyCapturingSender();
$dedupeBeacon = new Beacon(new Config(resource: ['service.name' => 'symfony-test']), $dedupeSender);
$dedupeRegistry = new ExceptionCaptureRegistry();
$dedupeSubscriber = new ExceptionSubscriber($dedupeBeacon, captureRegistry: $dedupeRegistry);
$dedupeLogger = new Logger('app');
$dedupeLogger->pushHandler(new ExceptionMonologHandler($dedupeBeacon, $dedupeRegistry));
$kernelThrowable = new RuntimeException('kernel failure');
$dedupeRequest = Request::create('/deduplicated', 'GET');
$dedupeResponse = new Response('', 500);
$dedupeSubscriber->onException(new ExceptionEvent($kernel, $dedupeRequest, HttpKernelInterface::MAIN_REQUEST, $kernelThrowable));
$dedupeLogger->critical('Unhandled exception', ['exception' => $kernelThrowable]);
$dedupeSubscriber->onResponse(new ResponseEvent($kernel, $dedupeRequest, HttpKernelInterface::MAIN_REQUEST, $dedupeResponse));
$dedupeSubscriber->onTerminate(new TerminateEvent($kernel, $dedupeRequest, $dedupeResponse));
symfonyCheck('kernel and Monolog paths deduplicate the same Throwable', \count($dedupeSender->captured['errors'] ?? []) === 1);
symfonyCheck('kernel subscriber remains owner of the deduplicated issue', ($dedupeSender->captured['errors'][0]['handled'] ?? null) === false);

$disabledSender = new SymfonyCapturingSender();
$disabledBeacon = new Beacon(new Config(resource: ['service.name' => 'symfony-test']), $disabledSender);
$disabledLogger = new Logger('app');
$disabledLogger->pushHandler(new ExceptionMonologHandler($disabledBeacon, new ExceptionCaptureRegistry(), enabled: false));
$disabledLogger->error('Ignored handled exception', ['exception' => new RuntimeException('disabled')]);
symfonyCheck('Monolog exception capture can be disabled', ($disabledSender->captured['errors'] ?? []) === []);

$prependContainer = new ContainerBuilder();
$prependContainer->registerExtension(new class extends Extension {
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'monolog';
    }
});
(new BeaconExtension())->prepend($prependContainer);
$prependedMonolog = $prependContainer->getExtensionConfig('monolog');
symfonyCheck(
    'Symfony bundle automatically wires the exception-only Monolog handler',
    ($prependedMonolog[0]['handlers']['beacon_exception_issues']['id'] ?? null) === 'beacon.exception_monolog_handler',
);

$container = new ContainerBuilder();
$container->setParameter('kernel.environment', 'test');
$container->setParameter('kernel.project_dir', __DIR__);
$container->setParameter('kernel.secret', 'integration-secret');
$container->setDefinition('security.token_storage', new Definition(SymfonyTestTokenStorage::class));
$container->setDefinition(\Symfony\Component\HttpClient\Psr18Client::class, new Definition(\Symfony\Component\HttpClient\Psr18Client::class));
(new BeaconExtension())->load([[]], $container);
$container->getAlias('beacon.exception_monolog_handler')->setPublic(true);
$container->getAlias(UserContextProviderInterface::class)->setPublic(true);
$container->compile();
$userProvider = $container->get(UserContextProviderInterface::class);
$expectedUserId = 'usr_'.hash_hmac('sha256', 'private@example.test', 'integration-secret');
symfonyCheck('Symfony DI enables pseudonymous user context from kernel.secret', $userProvider->userId() === $expectedUserId);
symfonyCheck('Symfony DI never exposes the raw authenticated identifier', !str_contains($userProvider->userId() ?? '', 'private@example.test'));
symfonyCheck('Symfony DI exposes the official exception Monolog handler', $container->get('beacon.exception_monolog_handler') instanceof ExceptionMonologHandler);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
