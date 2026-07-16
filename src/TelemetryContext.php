<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

/** Mutable request-local correlation state shared by framework integrations. */
final class TelemetryContext
{
    private ?string $traceId = null;
    private ?string $spanId = null;
    private bool $failed = false;

    public function begin(string $traceId, string $spanId): void
    {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->failed = false;
    }

    public function markFailed(): void
    {
        if ($this->traceId !== null) {
            $this->failed = true;
        }
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function failed(): bool
    {
        return $this->failed;
    }

    public function reset(): void
    {
        $this->traceId = null;
        $this->spanId = null;
        $this->failed = false;
    }
}
