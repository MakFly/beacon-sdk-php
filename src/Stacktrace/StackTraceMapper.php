<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Stacktrace;

use KevStudios\Beacon\Config;

/**
 * Maps a Throwable to Beacon stack frames: file/line/class/method, a code snippet around
 * the line, application-frame detection, and (optionally) reduced argument values.
 */
final class StackTraceMapper
{
    private const SNIPPET_RADIUS = 5;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function map(\Throwable $throwable): array
    {
        $frames = [];

        // The throwable's own location is the topmost frame.
        $frames[] = $this->buildFrame($throwable->getFile(), $throwable->getLine(), null, null, null);

        foreach ($throwable->getTrace() as $trace) {
            $file = isset($trace['file']) && \is_string($trace['file']) ? $trace['file'] : '[internal]';
            $line = isset($trace['line']) && \is_int($trace['line']) ? $trace['line'] : 0;
            $class = isset($trace['class']) && \is_string($trace['class']) ? $trace['class'] : null;
            $method = isset($trace['function']) && \is_string($trace['function']) ? $trace['function'] : null;
            $args = $this->config->collectArguments && isset($trace['args']) && \is_array($trace['args'])
                ? $this->reduceArguments($trace['args'])
                : null;

            $frames[] = $this->buildFrame($file, $line, $class, $method, $args);
        }

        return $frames;
    }

    /**
     * @param list<array<string, mixed>>|null $arguments
     *
     * @return array<string, mixed>
     */
    private function buildFrame(string $file, int $line, ?string $class, ?string $method, ?array $arguments): array
    {
        return [
            'file' => $file,
            'lineNumber' => $line,
            'class' => $class,
            'method' => $method,
            'isApplicationFrame' => $this->isApplicationFrame($file),
            'codeSnippet' => $this->snippet($file, $line),
            'arguments' => $arguments,
        ];
    }

    private function isApplicationFrame(string $file): bool
    {
        if (str_contains($file, '/vendor/') || $file === '[internal]') {
            return false;
        }
        $root = $this->config->applicationPath;

        return $root === null || str_starts_with($file, $root);
    }

    /**
     * @return array<string, string>|null
     */
    private function snippet(string $file, int $line): ?array
    {
        if ($line < 1 || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $lines = @file($file, \FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        $start = max(1, $line - self::SNIPPET_RADIUS);
        $end = min(\count($lines), $line + self::SNIPPET_RADIUS);
        $snippet = [];
        for ($i = $start; $i <= $end; ++$i) {
            $snippet[(string) $i] = $this->truncate($lines[$i - 1] ?? '');
        }

        return $snippet;
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return list<array<string, mixed>>
     */
    private function reduceArguments(array $args): array
    {
        $reduced = [];
        foreach ($args as $value) {
            $reduced[] = $this->reduceValue($value);
        }

        return $reduced;
    }

    /**
     * @return array<string, mixed>
     */
    private function reduceValue(mixed $value): array
    {
        $type = get_debug_type($value);
        [$repr, $truncated] = match (true) {
            \is_scalar($value) || $value === null => [$value, false],
            \is_array($value) => ['array('.\count($value).')', false],
            $value instanceof \Throwable => [$value::class.': '.$value->getMessage(), false],
            \is_object($value) => [$value::class, false],
            default => [$type, false],
        };

        if (\is_string($repr) && \strlen($repr) > $this->config->maxStringLength) {
            $repr = substr($repr, 0, $this->config->maxStringLength);
            $truncated = true;
        }

        return ['value' => $repr, 'original_type' => $type, 'truncated' => $truncated];
    }

    private function truncate(string $value): string
    {
        return \strlen($value) > $this->config->maxStringLength
            ? substr($value, 0, $this->config->maxStringLength)
            : $value;
    }
}
