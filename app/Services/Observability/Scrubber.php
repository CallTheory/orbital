<?php

declare(strict_types=1);

namespace App\Services\Observability;

/**
 * Strips the things an answering service must not ship off-site.
 *
 * Orbital handles other people's callers. A stack trace taken at the
 * wrong moment can carry a caller's name and number, the body of a text
 * somebody sent to a doctor's office, a recording URL, the DTMF a
 * caller typed into a payment prompt, or a carrier credential. Whoever
 * the operator points the error project at — their own GlitchTip, or
 * somebody else's SaaS — none of that should leave the building.
 *
 * A denylist by key, applied to structured data only. Deliberately not
 * applied to exception messages or stack frames: those are the reason
 * the report exists, redacting them blind would produce reports nobody
 * can act on, and a key-based rule cannot tell a caller's number from a
 * line number inside a free-text string anyway. The defence against a
 * message that quotes customer data is not to send request bodies at
 * all, which is what `send_default_pii => false` does.
 *
 * Matching is on the key, case-insensitively, by substring — because
 * the same value arrives under `phone`, `caller_phone`, `from_phone`
 * and `phone_number` depending on which layer built the array, and
 * listing every spelling is how a scrubber quietly stops working.
 */
class Scrubber
{
    public const REDACTED = '[redacted]';

    /**
     * Key fragments that mean "this value is somebody's private
     * business, or a credential".
     *
     * @var array<int, string>
     */
    private const SENSITIVE = [
        // Credentials.
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth_token', 'credential', 'private_key',
        'dsn', 'signature', 'session',

        // The caller, and what they said.
        'caller_name', 'caller_phone', 'phone', 'msisdn', 'did',
        'from_address', 'to_address', 'remote_address',
        'body', 'message_body', 'reason', 'transcript', 'dtmf', 'digits',
        'recording', 'media_url', 'voicemail',

        // Account identity.
        'email', 'address', 'ssn', 'dob', 'date_of_birth',
    ];

    /**
     * Keys that look sensitive by the rule above but are not, and are
     * needed to make a report actionable.
     *
     * `ip_address` is out on purpose — it is not on this list because
     * it is not collected in the first place.
     *
     * @var array<int, string>
     */
    private const KEEP = [
        'address_key',      // opt-out lookup hash, already one-way
        'body_size',
        'to_address_count',
    ];

    /**
     * Recursively redact sensitive values, preserving shape.
     *
     * Shape is preserved rather than the key dropped so a report still
     * shows that a field was present — "we had a callback number and it
     * was redacted" is a different diagnosis from "there was no
     * callback number", and the second is what a dropped key looks
     * like.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data, int $depth = 0): array
    {
        // Cheap guard against a self-referential or pathological
        // structure; nothing legitimate in an error context nests this
        // far.
        if ($depth > 12) {
            return [self::REDACTED];
        }

        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->scrub($value, $depth + 1);

                continue;
            }

            $out[$key] = $this->isSensitive((string) $key) ? self::REDACTED : $value;
        }

        return $out;
    }

    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::KEEP as $keep) {
            if ($key === $keep) {
                return false;
            }
        }

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
