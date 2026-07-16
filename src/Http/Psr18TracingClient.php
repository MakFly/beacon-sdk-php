<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Http;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Ids;
use KevStudios\Beacon\Protocol;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Time;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Records privacy-safe PSR-18 calls as children of the active Symfony request span. */
final class Psr18TracingClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Beacon $beacon,
        private readonly RequestSpanSubscriber $requestSpan,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->beacon->isEnabled()) {
            return $this->inner->sendRequest($request);
        }

        $traceId = $this->requestSpan->traceId();
        $parentSpanId = $this->requestSpan->spanId();
        if ($traceId === null || $parentSpanId === null) {
            return $this->inner->sendRequest($request);
        }

        $start = Time::nowNano();
        $response = null;
        $error = null;

        try {
            $response = $this->inner->sendRequest($request);

            return $response;
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            $method = strtoupper($request->getMethod());
            $host = strtolower($request->getUri()->getHost()) ?: 'unknown';
            $statusCode = $response?->getStatusCode();
            $failed = $error !== null || ($statusCode !== null && $statusCode >= 400);
            $attributes = array_filter([
                Protocol::ATTR_SPAN_TYPE => Protocol::SPAN_HTTP_CLIENT,
                'http.request.method' => $method,
                'http.response.status_code' => $statusCode,
                'server.address' => $host,
                'server.port' => $request->getUri()->getPort(),
                'url.scheme' => $request->getUri()->getScheme() ?: null,
                'network.protocol.version' => $response?->getProtocolVersion(),
                'error.type' => $error !== null ? $error::class : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->beacon->captureSpans([[
                'traceId' => $traceId,
                'spanId' => Ids::spanId(),
                'parentSpanId' => $parentSpanId,
                'name' => $method.' '.$host,
                'startTimeUnixNano' => $start,
                'endTimeUnixNano' => Time::nowNano(),
                'status' => ['code' => $failed ? Protocol::STATUS_ERROR : Protocol::STATUS_OK],
                'attributes' => $attributes,
                'events' => [],
            ]]);
        }
    }
}
