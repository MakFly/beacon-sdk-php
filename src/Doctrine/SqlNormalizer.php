<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Doctrine;

/** Produces a bounded, low-cardinality SQL operation name without literal values. */
final class SqlNormalizer
{
    public static function normalize(string $sql): string
    {
        $normalized = preg_replace("/(?:E)?'(?:''|\\\\.|[^'])*'/is", '?', $sql) ?? $sql;
        $normalized = preg_replace('/\$\d+\b/', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?)\b/i', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\?(?:\s*,\s*\?)+/', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = rtrim(trim($normalized), ';');

        return substr($normalized !== '' ? $normalized : 'SQL query', 0, 2048);
    }

    public static function operation(string $normalizedSql): string
    {
        if (preg_match('/^([a-z]+)/i', $normalizedSql, $match) === 1) {
            return strtoupper($match[1]);
        }

        return 'QUERY';
    }
}
