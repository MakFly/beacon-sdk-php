<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Propagation;

final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled,
        public ?string $tracestate = null,
        public ?string $baggage = null,
    ) {
    }
}
