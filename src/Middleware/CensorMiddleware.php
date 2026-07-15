<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Middleware;

use KevStudios\Beacon\Report\ErrorReport;

/** Redacts attribute values whose key contains any configured sensitive token. */
final class CensorMiddleware implements BeaconMiddleware
{
    private readonly Redactor $redactor;

    /** @param list<string> $censorKeys */
    public function __construct(array $censorKeys)
    {
        $this->redactor = new Redactor($censorKeys);
    }

    public function handle(ErrorReport $report, \Closure $next): ErrorReport
    {
        $report->resource = $this->redactor->redact($report->resource);
        $report->attributes = $this->redactor->redact($report->attributes);
        $report->events = $this->redactor->redact($report->events);
        $report->stacktrace = $this->redactor->redact($report->stacktrace);

        return $next($report);
    }
}
