<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\EventSubscriber;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Protocol;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Captures unhandled kernel exceptions and flushes the Beacon buffer at request end.
 * Pure Symfony wiring — no external telemetry dependency.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Beacon $beacon)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // High priority so we record before other listeners transform the exception.
            KernelEvents::EXCEPTION => ['onException', 256],
            KernelEvents::TERMINATE => ['onTerminate', -256],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        $this->beacon->captureException(
            $event->getThrowable(),
            handled: false,
            attributes: $this->requestAttributes($request),
        );
    }

    public function onTerminate(TerminateEvent $event): void
    {
        // Buffer is also flushed on shutdown, but terminate is the clean path under php-fpm.
        $this->beacon->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAttributes(Request $request): array
    {
        $route = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');

        return array_filter([
            Protocol::ATTR_ENTRY_POINT_TYPE => Protocol::ENTRY_WEB,
            Protocol::ATTR_ENTRY_POINT_VALUE => $request->getUri(),
            Protocol::ATTR_HANDLER_IDENTIFIER => $request->getMethod().' '.($route ?? $request->getPathInfo()),
            Protocol::ATTR_HANDLER_NAME => \is_string($controller) ? $controller : null,
            Protocol::ATTR_HANDLER_TYPE => Protocol::HANDLER_SYMFONY_CONTROLLER,
            'http.request.method' => $request->getMethod(),
            'http.route' => \is_string($route) ? $route : null,
            'url.full' => substr($request->getUri(), 0, 2048),
            'url.path' => $request->getPathInfo(),
            'client.address' => $request->getClientIp(),
            'user_agent.original' => substr((string) $request->headers->get('User-Agent'), 0, 512),
        ], static fn ($v) => $v !== null);
    }
}
