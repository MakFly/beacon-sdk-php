<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\EventSubscriber;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Ids;
use KevStudios\Beacon\Protocol;
use KevStudios\Beacon\Symfony\UserContextProviderInterface;
use KevStudios\Beacon\TelemetryContext;
use KevStudios\Beacon\Time;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Captures a root HTTP span for every request and flushes the trace at terminate.
 * Gives the dashboard traces, performance data, and request-level visibility.
 */
final class RequestSpanSubscriber implements EventSubscriberInterface
{
    private ?string $traceId = null;
    private ?string $spanId = null;
    private ?string $startNano = null;
    private ?Request $request = null;
    private int $statusCode = 200;

    public function __construct(
        private readonly Beacon $beacon,
        private readonly ?RouterInterface $router = null,
        private readonly ?TelemetryContext $context = null,
        private readonly ?UserContextProviderInterface $userContext = null,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1024],
            KernelEvents::RESPONSE => ['onResponse', -1024],
            KernelEvents::TERMINATE => ['onTerminate', -128],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->beacon->isEnabled()) {
            return;
        }

        $this->traceId = Ids::traceId();
        $this->spanId = Ids::spanId();
        $this->startNano = Time::nowNano();
        $this->request = $event->getRequest();
        $this->statusCode = 200;
        $this->context?->begin($this->traceId, $this->spanId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->statusCode = $event->getResponse()->getStatusCode();
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->traceId === null || $this->request === null) {
            return;
        }

        $endNano = Time::nowNano();
        $request = $this->request;
        $routeName = $request->attributes->get('_route');
        $routePath = $this->routePath($request);
        $controller = $request->attributes->get('_controller');
        $isError = $this->statusCode >= 500 || ($this->context?->failed() ?? false);
        $userId = $this->userContext?->userId();

        $name = $this->httpOperationName($request, $routePath);

        $span = [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'parentSpanId' => null,
            'name' => $name,
            'startTimeUnixNano' => $this->startNano,
            'endTimeUnixNano' => $endNano,
            'status' => ['code' => $isError ? Protocol::STATUS_ERROR : Protocol::STATUS_OK],
            'attributes' => array_filter([
                Protocol::ATTR_SPAN_TYPE => Protocol::SPAN_HTTP_REQUEST,
                Protocol::ATTR_ENTRY_POINT_TYPE => Protocol::ENTRY_WEB,
                Protocol::ATTR_ENTRY_POINT_VALUE => substr($request->getUri(), 0, 2048),
                Protocol::ATTR_HANDLER_IDENTIFIER => $name,
                Protocol::ATTR_HANDLER_NAME => \is_string($controller) ? $controller : null,
                Protocol::ATTR_HANDLER_TYPE => Protocol::HANDLER_SYMFONY_CONTROLLER,
                'http.request.method' => $request->getMethod(),
                'http.route' => $routePath,
                'symfony.route.name' => \is_string($routeName) ? $routeName : null,
                'http.response.status_code' => $this->statusCode,
                'url.path' => $request->getPathInfo(),
                'client.address' => $request->getClientIp(),
                'user_agent.original' => substr((string) $request->headers->get('User-Agent'), 0, 512),
                'user.id' => $userId,
            ], static fn ($v) => $v !== null),
            'events' => [],
        ];

        $this->beacon->captureSpans([$span], force: $isError, complete: true);
        $this->beacon->flush();

        $this->traceId = null;
        $this->spanId = null;
        $this->startNano = null;
        $this->request = null;
        $this->context?->reset();
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    private function httpOperationName(Request $request, string $routePath): string
    {
        return trim($request->getMethod().' '.$routePath);
    }

    private function routePath(Request $request): string
    {
        $routeName = $request->attributes->get('_route');
        if ($this->router !== null && \is_string($routeName) && $routeName !== '') {
            $route = $this->router->getRouteCollection()->get($routeName);
            $path = $route?->getPath();
            if (\is_string($path) && $path !== '') {
                return $path;
            }
        }

        return $request->getPathInfo();
    }
}
