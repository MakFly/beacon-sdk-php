<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

use KevStudios\Beacon\Middleware\BeaconMiddleware;
use KevStudios\Beacon\Middleware\CensorMiddleware;
use KevStudios\Beacon\Middleware\Redactor;
use KevStudios\Beacon\Report\ErrorReport;
use KevStudios\Beacon\Report\ExceptionReportBuilder;
use KevStudios\Beacon\Stacktrace\StackTraceMapper;
use KevStudios\Beacon\Transport\AvailabilityAwareSenderInterface;
use KevStudios\Beacon\Transport\SenderInterface;

/**
 * Home-grown PHP telemetry client. Buffers errors/traces/logs during a request and
 * flushes them to the Beacon ingester (on flush() or process shutdown). No external
 * instrumentation dependency — only ext-curl and ext-json.
 */
final class Beacon
{
    private readonly ExceptionReportBuilder $builder;
    private readonly Redactor $redactor;

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
        $this->redactor = new Redactor($config->censorKeys);
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
        if (!$this->isEnabled()) {
            return;
        }
        $report = $this->builder->build($throwable, $handled, $attributes);
        $report = $this->runPipeline($report);
        $this->appendBounded($this->errorBuffer, $report->toArray());
        $this->registerShutdown();
    }

    /**
     * Buffer one trace (a list of finished spans sharing a traceId).
     *
     * @param list<array<string, mixed>> $spans
     */
    public function captureSpans(array $spans, string $scopeName = 'beacon-sdk-php', string $scopeVersion = '0.5.1'): void
    {
        if (!$this->isEnabled() || $spans === [] || !$this->shouldSample($spans)) {
            return;
        }
        $this->appendBounded($this->spanBuffer, [
            'resource' => $this->redactor->redact($this->config->resource),
            'scopes' => [['name' => $scopeName, 'version' => $scopeVersion, 'spans' => $this->redactor->redact($spans)]],
        ]);
        $this->registerShutdown();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function log(string $level, string $body, array $attributes = [], ?string $traceId = null, ?string $spanId = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $severity = Protocol::SEVERITY[strtoupper($level)] ?? Protocol::SEVERITY['INFO'];
        $this->appendBounded($this->logBuffer, [
            'resource' => $this->redactor->redact($this->config->resource),
            'records' => [[
                'timeUnixNano' => Time::nowNano(),
                'severityNumber' => $severity,
                'severityText' => strtoupper($level),
                'body' => $body,
                'traceId' => $traceId,
                'spanId' => $spanId,
                'attributes' => $this->redactor->redact($attributes),
            ]],
        ]);
        $this->registerShutdown();
    }

    /** Send everything buffered now. */
    public function flush(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($this->errorBuffer !== []) {
            if ($this->sender->send('errors', $this->errorBuffer)) {
                $this->errorBuffer = [];
            }
        }
        if ($this->spanBuffer !== []) {
            if ($this->sender->send('traces', $this->spanBuffer)) {
                $this->spanBuffer = [];
            }
        }
        if ($this->logBuffer !== []) {
            if ($this->sender->send('logs', $this->logBuffer)) {
                $this->logBuffer = [];
            }
        }
    }

    public function isEnabled(): bool
    {
        return !($this->sender instanceof AvailabilityAwareSenderInterface) || $this->sender->isAvailable();
    }

    /** @param list<array<string, mixed>> $buffer @param array<string, mixed> $payload */
    private function appendBounded(array &$buffer, array $payload): void
    {
        $maximum = max(1, $this->config->maxBacklogItems);
        if (\count($buffer) >= $maximum) {
            array_shift($buffer);
        }
        $buffer[] = $payload;
    }

    /** @param list<array<string, mixed>> $spans */
    private function shouldSample(array $spans): bool
    {
        $rate = max(0.0, min(1.0, $this->config->tracesSampleRate));
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 1.0) {
            return true;
        }

        $traceId = $spans[0]['traceId'] ?? null;
        if (\is_string($traceId) && preg_match('/^[0-9a-f]{8}/i', $traceId) === 1) {
            return hexdec(substr($traceId, 0, 8)) / 4294967296 < $rate;
        }

        return random_int(0, 1_000_000) / 1_000_000 < $rate;
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
