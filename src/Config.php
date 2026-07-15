<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

/** Immutable SDK configuration: resource attributes + data-collection toggles. */
final class Config
{
    /**
     * @param array<string, scalar|null> $resource          Resource attributes (service.name required)
     * @param list<string>               $censorKeys        Attribute keys whose values are redacted
     * @param int                        $maxStringLength   Truncate long attribute strings
     * @param bool                       $collectArguments  Capture stack-frame argument values
     * @param float                      $tracesSampleRate  0.0–1.0 head sampling for traces
     */
    public function __construct(
        public readonly array $resource,
        public readonly array $censorKeys = ['password', 'authorization', 'cookie', 'token', 'secret', 'api_key'],
        public readonly int $maxStringLength = 4096,
        public readonly bool $collectArguments = false,
        public readonly float $tracesSampleRate = 1.0,
        public readonly ?string $applicationPath = null,
    ) {
    }

    public function serviceName(): string
    {
        $name = $this->resource['service.name'] ?? null;

        return \is_string($name) && $name !== '' ? $name : 'unknown-service';
    }
}
