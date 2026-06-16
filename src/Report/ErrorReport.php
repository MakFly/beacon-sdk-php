<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Report;

/**
 * Mutable builder for a /v1/errors payload. Middleware enrich this before it is sent.
 * Mirrors the ErrorPayload shape in the wire-protocol.
 */
final class ErrorReport
{
    /** @var array<string, mixed> */
    public array $attributes = [];

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $stacktrace = [];

    public ?string $overriddenGrouping = null;

    /**
     * @param array<string, scalar|null> $resource
     */
    public function __construct(
        public array $resource,
        public string $seenAtUnixNano,
        public ?string $exceptionClass = null,
        public ?string $message = null,
        public ?string $code = null,
        public ?bool $handled = true,
        public ?string $applicationPath = null,
        public ?int $openFrameIndex = 0,
        public ?string $trackingUuid = null,
        public ?string $sourcemapVersionId = null,
    ) {
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function addEvent(string $type, string $startNano, ?string $endNano, array $attributes): self
    {
        $this->events[] = [
            'type' => $type,
            'startTimeUnixNano' => $startNano,
            'endTimeUnixNano' => $endNano,
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'trackingUuid' => $this->trackingUuid,
            'seenAtUnixNano' => $this->seenAtUnixNano,
            'exceptionClass' => $this->exceptionClass,
            'message' => $this->message,
            'code' => $this->code,
            'handled' => $this->handled,
            'applicationPath' => $this->applicationPath,
            'openFrameIndex' => $this->openFrameIndex,
            'sourcemapVersionId' => $this->sourcemapVersionId,
            'overriddenGrouping' => $this->overriddenGrouping,
            'attributes' => $this->attributes,
            'events' => $this->events,
            'stacktrace' => $this->stacktrace,
        ];
    }
}
