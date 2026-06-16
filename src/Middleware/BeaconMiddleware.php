<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Middleware;

use KevStudios\Beacon\Report\ErrorReport;

/**
 * Composable report middleware (Flare-style pipeline). Each middleware may enrich the
 * report then call $next, or short-circuit. Register via Beacon::pushMiddleware().
 */
interface BeaconMiddleware
{
    public function handle(ErrorReport $report, \Closure $next): ErrorReport;
}
