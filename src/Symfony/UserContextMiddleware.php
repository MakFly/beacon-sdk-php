<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony;

use KevStudios\Beacon\Middleware\BeaconMiddleware;
use KevStudios\Beacon\Report\ErrorReport;

final class UserContextMiddleware implements BeaconMiddleware
{
    public function __construct(private readonly UserContextProviderInterface $provider)
    {
    }

    public function handle(ErrorReport $report, \Closure $next): ErrorReport
    {
        $userId = $this->provider->userId();
        if ($userId !== null && !isset($report->attributes['user.id'])) {
            $report->setAttribute('user.id', $userId);
        }

        return $next($report);
    }
}
