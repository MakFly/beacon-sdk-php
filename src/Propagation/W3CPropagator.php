<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Propagation;

final class W3CPropagator
{
    public static function parseTraceparent(?string $value): ?TraceContext
    {
        if ($value === null || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', trim($value), $matches) !== 1) {
            return null;
        }
        if (preg_match('/^0+$/', $matches[1]) === 1 || preg_match('/^0+$/', $matches[2]) === 1) {
            return null;
        }

        return new TraceContext(
            strtolower($matches[1]),
            strtolower($matches[2]),
            (hexdec($matches[3]) & 1) === 1,
        );
    }

    public static function formatTraceparent(TraceContext $context): string
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $context->traceId) !== 1 || preg_match('/^0+$/', $context->traceId) === 1) {
            throw new \InvalidArgumentException('Invalid W3C trace id');
        }
        if (preg_match('/^[0-9a-f]{16}$/i', $context->spanId) !== 1 || preg_match('/^0+$/', $context->spanId) === 1) {
            throw new \InvalidArgumentException('Invalid W3C span id');
        }

        return sprintf(
            '00-%s-%s-%s',
            strtolower($context->traceId),
            strtolower($context->spanId),
            $context->sampled ? '01' : '00',
        );
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function inject(TraceContext $context, array $headers = []): array
    {
        $headers['traceparent'] = self::formatTraceparent($context);
        if ($context->tracestate !== null && $context->tracestate !== '') {
            $headers['tracestate'] = $context->tracestate;
        }
        if ($context->baggage !== null && $context->baggage !== '') {
            $headers['baggage'] = $context->baggage;
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    public static function extract(array $headers): ?TraceContext
    {
        $normalized = array_change_key_case($headers, \CASE_LOWER);
        $context = self::parseTraceparent($normalized['traceparent'] ?? null);
        if ($context === null) {
            return null;
        }

        return new TraceContext(
            $context->traceId,
            $context->spanId,
            $context->sampled,
            $normalized['tracestate'] ?? null,
            $normalized['baggage'] ?? null,
        );
    }
}
