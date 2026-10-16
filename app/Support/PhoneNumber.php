<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone number normalisation. Pure and static so telephony code and the
 * handoff API compare numbers the same way.
 */
final class PhoneNumber
{
    /**
     * Normalise to E.164, assuming North America for bare 10/11-digit
     * numbers; a leading + is kept as given. Returns null for null,
     * empty, or digit-less input.
     */
    public static function toE164(?string $input): ?string
    {
        $raw = trim((string) $input);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+'.$digits;
        }

        return '+'.$digits;
    }
}
