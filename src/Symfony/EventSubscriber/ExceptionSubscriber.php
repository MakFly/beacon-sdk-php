<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\EventSubscriber;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Protocol;
use KevStudios\Beacon\Symfony\ExceptionCaptureRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Captures unhandled kernel exceptions and flushes the Beacon buffer at request end.
 * Pure Symfony wiring — no external telemetry dependency.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    private ?\Throwable $pendingThrowable = null;
    private ?Request $pendingRequest = null;
    private readonly ExceptionCaptureRegistry $captureRegistry;

    public function __construct(
        private readonly Beacon $beacon,
        private readonly ?RouterInterface $router = null,
        ?ExceptionCaptureRegistry $captureRegistry = null,
    )
    {
        $this->captureRegistry = $captureRegistry ?? new ExceptionCaptureRegistry();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Remember before application listeners transform the exception, then classify it
            // from the final HTTP response instead of guessing too early.
            KernelEvents::EXCEPTION => ['onException', 256],
            KernelEvents::RESPONSE => ['onResponse', -512],
            // Run before RequestSpanSubscriber so the no-response fallback marks the
            // active request as failed while its correlation context still exists.
            KernelEvents::TERMINATE => ['onTerminate', 0],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->pendingThrowable = $event->getThrowable();
        $this->captureRegistry->markKernelException($this->pendingThrowable);
        $this->pendingRequest = $event->getRequest();
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->pendingThrowable === null || $this->pendingRequest === null) {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();
        $this->capturePending(handled: $statusCode < 500, statusCode: $statusCode);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->pendingThrowable !== null) {
            $this->capturePending(handled: false, statusCode: 500);
        }
        // Buffer is also flushed on shutdown, but terminate is the clean path under php-fpm.
        $this->beacon->flush();
    }

    private function capturePending(bool $handled, int $statusCode): void
    {
        if ($this->pendingThrowable === null || $this->pendingRequest === null) {
            return;
        }

        $this->beacon->captureException(
            $this->pendingThrowable,
            handled: $handled,
            attributes: $this->requestAttributes($this->pendingRequest) + ['http.response.status_code' => $statusCode],
        );
        $this->pendingThrowable = null;
        $this->pendingRequest = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAttributes(Request $request): array
    {
        $routeName = $request->attributes->get('_route');
        $routePath = $this->routePath($request);
        $controller = $request->attributes->get('_controller');
        $operation = $this->httpOperationName($request, $routePath);

        return array_filter([
            Protocol::ATTR_ENTRY_POINT_TYPE => Protocol::ENTRY_WEB,
            Protocol::ATTR_ENTRY_POINT_VALUE => $request->getUri(),
            Protocol::ATTR_HANDLER_IDENTIFIER => $operation,
            Protocol::ATTR_HANDLER_NAME => \is_string($controller) ? $controller : null,
            Protocol::ATTR_HANDLER_TYPE => Protocol::HANDLER_SYMFONY_CONTROLLER,
            'http.request.method' => $request->getMethod(),
            'http.route' => $routePath,
            'symfony.route.name' => \is_string($routeName) ? $routeName : null,
            'url.full' => substr($request->getUri(), 0, 2048),
            'url.path' => $request->getPathInfo(),
            'client.address' => $request->getClientIp(),
            'user_agent.original' => substr((string) $request->headers->get('User-Agent'), 0, 512),
        ], static fn ($v) => $v !== null);
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
