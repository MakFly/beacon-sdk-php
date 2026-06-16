<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

use KevStudios\Beacon\Middleware\BeaconMiddleware;
use KevStudios\Beacon\Middleware\CensorMiddleware;
use KevStudios\Beacon\Report\ErrorReport;
use KevStudios\Beacon\Report\ExceptionReportBuilder;
use KevStudios\Beacon\Stacktrace\StackTraceMapper;
use KevStudios\Beacon\Transport\SenderInterface;

/**
 * Home-grown PHP telemetry client. Buffers errors/traces/logs during a request and
 * flushes them to the Beacon ingester (on flush() or process shutdown). No external
 * instrumentation dependency — only ext-curl and ext-json.
 */
final class Beacon
{
    private readonly ExceptionReportBuilder $builder;

    /** @var list<BeaconMiddleware> */
    private array $middleware;

    /** @var list<array<string, mixed>> */
    private array $errorBuffer = [];

    /** @var list<array<string, mixed>> */
    private array $spanBuffer = [];

    /** @var list<array<string, mixed>> */
    private array $logBuffer = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Config $config,
        private readonly SenderInterface $sender,
    ) {
        $this->builder = new ExceptionReportBuilder($config, new StackTraceMapper($config));
        // Censoring is always last so middleware-added attributes are also scrubbed.
        $this->middleware = [new CensorMiddleware($config->censorKeys)];
    }

    public function pushMiddleware(BeaconMiddleware $middleware): self
    {
        // Keep CensorMiddleware last.
        array_splice($this->middleware, max(0, \count($this->middleware) - 1), 0, [$middleware]);

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function captureException(\Throwable $throwable, bool $handled = true, array $attributes = []): void
    {
        $report = $this->builder->build($throwable, $handled, $attributes);
        $report = $this->runPipeline($report);
        $this->errorBuffer[] = $report->toArray();
        $this->registerShutdown();
    }

    /**
     * Buffer one trace (a list of finished spans sharing a traceId).
     *
     * @param list<array<string, mixed>> $spans
     */
    public function captureSpans(array $spans, string $scopeName = 'beacon-sdk-php', string $scopeVersion = '0.1.0'): void
    {
        if ($spans === []) {
            return;
        }
        $this->spanBuffer[] = [
            'resource' => $this->config->resource,
            'scopes' => [['name' => $scopeName, 'version' => $scopeVersion, 'spans' => $spans]],
        ];
        $this->registerShutdown();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function log(string $level, string $body, array $attributes = [], ?string $traceId = null, ?string $spanId = null): void
    {
        $severity = Protocol::SEVERITY[strtoupper($level)] ?? Protocol::SEVERITY['INFO'];
        $this->logBuffer[] = [
            'resource' => $this->config->resource,
            'records' => [[
                'timeUnixNano' => Time::nowNano(),
                'severityNumber' => $severity,
                'severityText' => strtoupper($level),
                'body' => $body,
                'traceId' => $traceId,
                'spanId' => $spanId,
                'attributes' => $attributes,
            ]],
        ];
        $this->registerShutdown();
    }

    /** Send everything buffered now. */
    public function flush(): void
    {
        if ($this->errorBuffer !== []) {
            $this->sender->send('errors', $this->errorBuffer);
            $this->errorBuffer = [];
        }
        if ($this->spanBuffer !== []) {
            $this->sender->send('traces', $this->spanBuffer);
            $this->spanBuffer = [];
        }
        if ($this->logBuffer !== []) {
            $this->sender->send('logs', $this->logBuffer);
            $this->logBuffer = [];
        }
    }

    private function runPipeline(ErrorReport $report): ErrorReport
    {
        $next = static fn (ErrorReport $r): ErrorReport => $r;
        foreach (array_reverse($this->middleware) as $middleware) {
            $current = $next;
            $next = static fn (ErrorReport $r): ErrorReport => $middleware->handle($r, $current);
        }

        return $next($report);
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }
}
