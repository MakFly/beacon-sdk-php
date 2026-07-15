<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Doctrine;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Ids;
use KevStudios\Beacon\Protocol;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Time;

final class DoctrineSpanRecorder
{
    public function __construct(
        private readonly Beacon $beacon,
        private readonly RequestSpanSubscriber $requestSpan,
        private readonly string $dbSystem,
    ) {
    }

    public function record(string $sql, callable $operation): mixed
    {
        $traceId = $this->requestSpan->traceId();
        $parentSpanId = $this->requestSpan->spanId();
        if ($traceId === null || $parentSpanId === null) {
            return $operation();
        }

        $start = Time::nowNano();
        $error = null;
        try {
            return $operation();
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            $normalized = SqlNormalizer::normalize($sql);
            $attributes = [
                Protocol::ATTR_SPAN_TYPE => Protocol::SPAN_DB_QUERY,
                'db.system.name' => $this->dbSystem,
                'db.operation.name' => SqlNormalizer::operation($normalized),
                'db.query.summary' => $normalized,
            ];
            if ($error !== null) {
                $attributes['error.type'] = $error::class;
            }

            $this->beacon->captureSpans([[
                'traceId' => $traceId,
                'spanId' => Ids::spanId(),
                'parentSpanId' => $parentSpanId,
                'name' => $normalized,
                'startTimeUnixNano' => $start,
                'endTimeUnixNano' => Time::nowNano(),
                'status' => ['code' => $error === null ? Protocol::STATUS_OK : Protocol::STATUS_ERROR],
                'attributes' => $attributes,
                'events' => [],
            ]]);
        }
    }
}
