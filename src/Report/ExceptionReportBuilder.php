<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Report;

use KevStudios\Beacon\Config;
use KevStudios\Beacon\Ids;
use KevStudios\Beacon\Stacktrace\StackTraceMapper;
use KevStudios\Beacon\Time;

/** Builds the initial ErrorReport from a Throwable before middleware enrichment. */
final class ExceptionReportBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly StackTraceMapper $mapper,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function build(\Throwable $throwable, bool $handled, array $attributes = []): ErrorReport
    {
        $stacktrace = $this->mapper->map($throwable);
        $openFrameIndex = 0;
        foreach ($stacktrace as $index => $frame) {
            if (($frame['isApplicationFrame'] ?? false) === true) {
                $openFrameIndex = $index;
                break;
            }
        }

        $report = new ErrorReport(
            resource: $this->config->resource,
            seenAtUnixNano: Time::nowNano(),
            exceptionClass: $throwable::class,
            message: $throwable->getMessage(),
            code: $throwable->getCode() !== 0 ? substr((string) $throwable->getCode(), 0, 64) : null,
            handled: $handled,
            applicationPath: $this->config->applicationPath,
            openFrameIndex: $openFrameIndex,
            trackingUuid: Ids::uuid(),
        );
        $report->stacktrace = $stacktrace;
        $report->attributes = $attributes;

        if (method_exists($throwable, 'getSql')) {
            /** @phpstan-ignore-next-line dynamic method */
            $sql = (string) $throwable->getSql();
            $report->setAttribute('db.statement', $this->normalizeSql($sql));
        }

        return $report;
    }

    /** Strip literal values from SQL to avoid leaking PII in telemetry. */
    private function normalizeSql(string $sql): string
    {
        $sql = preg_replace("/'.+?'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;

        return $sql;
    }
}
