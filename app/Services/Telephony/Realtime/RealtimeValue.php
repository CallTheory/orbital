<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

/**
 * Asterisk's realtime drivers split every column value on ";" (one
 * column can carry several values) and decode "^XX" hex escapes in each
 * piece. A value that must keep a literal ";" — `sip:proxy;lr`, a
 * password — has to be stored as "^3B", or Asterisk sees only the last
 * piece (outbound_proxy "lr").
 */
final class RealtimeValue
{
    public static function encode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // "^" first so the escapes added for ";" aren't re-escaped.
        return str_replace([chr(94), ';'], ['^5E', '^3B'], $value);
    }

    public static function decode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(['^3B', '^5E'], [';', chr(94)], $value);
    }
}
