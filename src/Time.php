<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

/** Unix-nanosecond timestamps as strings (PHP ints are 64-bit but JSON-safe as strings). */
final class Time
{
    /** Current time in Unix nanoseconds. */
    public static function nowNano(): string
    {
        // microtime(true) gives microsecond resolution; scale to nanoseconds.
        return self::fromSeconds(microtime(true));
    }

    /** Convert a float seconds value (e.g. microtime(true) or a hrtime delta) to ns string. */
    public static function fromSeconds(float $seconds): string
    {
        // bcmath-free: split to avoid float precision loss past microseconds.
        $whole = (int) $seconds;
        $frac = $seconds - $whole;
        $nanos = $whole * 1_000_000_000 + (int) round($frac * 1_000_000_000);

        return (string) $nanos;
    }

    /** hrtime(true) returns nanoseconds as an int already. */
    public static function fromHrtime(int $hrtimeNanos): string
    {
        return (string) $hrtimeNanos;
    }
}
