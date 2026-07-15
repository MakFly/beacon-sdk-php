<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Middleware;

final class Redactor
{
    private const PLACEHOLDER = '[CENSORED]';

    /** @param list<string> $censorKeys */
    public function __construct(private readonly array $censorKeys)
    {
    }

    /** @param array<array-key, mixed> $values @return array<array-key, mixed> */
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && $this->isSensitive($key)) {
                $values[$key] = self::PLACEHOLDER;
            } elseif (\is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->censorKeys as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
