<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Parses the local-part of an inbound email address into a
 * client account_number and optional function sub-routing key.
 *
 * Grammar (matches the user's SendGrid Inbound Parse convention
 * from prior platforms):
 *
 *   {account_number}@{inbound_domain}
 *   {account_number}.{function}@{inbound_domain}
 *
 * Examples:
 *
 *   "12345@inbound.orbital.test"
 *     → ['account_number' => '12345', 'function' => null]
 *
 *   "12345.alarms@inbound.orbital.test"
 *     → ['account_number' => '12345', 'function' => 'alarms']
 *
 *   "12345.support.priority@inbound.orbital.test"
 *     → ['account_number' => '12345', 'function' => 'support.priority']
 *     (anything after the first dot is the function string,
 *      preserved verbatim so multi-segment function names work)
 *
 * Anything that doesn't start with a numeric account_number
 * gets `null` for account_number. The router falls through to
 * "unrouted" storage in that case.
 */
class LocalPartParser
{
    /**
     * Split a full email address into account_number + function.
     *
     * @return array{account_number: ?string, function: ?string}
     */
    public function parse(string $email): array
    {
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);

        // First dot separates the account number from the
        // function string. If there's no dot, the whole
        // local-part is the account number.
        $firstDot = strpos($local, '.');
        if ($firstDot === false) {
            return [
                'account_number' => $this->normalizeAccountNumber($local),
                'function' => null,
            ];
        }

        $accountCandidate = substr($local, 0, $firstDot);
        $function = substr($local, $firstDot + 1);

        return [
            'account_number' => $this->normalizeAccountNumber($accountCandidate),
            'function' => $function !== '' ? $function : null,
        ];
    }

    /**
     * Returns the input trimmed if it looks like a numeric
     * account number, otherwise null. We're deliberately strict
     * here — the client routing only works off numeric account
     * numbers, and accepting arbitrary strings would let any
     * mail to `support@...` collide with unrelated clients.
     */
    private function normalizeAccountNumber(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || ! ctype_digit($candidate)) {
            return null;
        }
        return $candidate;
    }
}
