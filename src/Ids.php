<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

/** OTel-shaped trace/span id generation. */
final class Ids
{
    /** 16 random bytes → 32 lowercase hex chars. */
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 8 random bytes → 16 lowercase hex chars. */
    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** RFC4122 v4 UUID. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}
