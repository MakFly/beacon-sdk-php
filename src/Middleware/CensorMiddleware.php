<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Middleware;

use KevStudios\Beacon\Report\ErrorReport;

/** Redacts attribute values whose key contains any configured sensitive token. */
final class CensorMiddleware implements BeaconMiddleware
{
    private const PLACEHOLDER = '[CENSORED]';

    /** @param list<string> $censorKeys */
    public function __construct(private readonly array $censorKeys)
    {
    }

    public function handle(ErrorReport $report, \Closure $next): ErrorReport
    {
        foreach ($report->attributes as $key => $value) {
            if ($this->isSensitive($key)) {
                $report->attributes[$key] = self::PLACEHOLDER;
            }
        }

        return $next($report);
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->censorKeys as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
