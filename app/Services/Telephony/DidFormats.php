<?php

declare(strict_types=1);

namespace App\Services\Telephony;

/**
 * DIDs are stored in E.164 (+18325790579), but carriers deliver the
 * dialled number in whatever form they like: Bandwidth sends the bare
 * national number (8325790579), others 1+10 digits or E.164 without the
 * "+". The inbound dispatcher accepts each form for an E.164 DID.
 */
final class DidFormats
{
    /**
     * Other forms of an E.164 number a carrier may send. Anything that
     * isn't a plain E.164 number (a dialplan pattern, say) has none.
     *
     * @return list<string>
     */
    public static function alternatives(string $number): array
    {
        if (preg_match('/^\+1(\d{10})$/', $number, $m)) {
            return ['1'.$m[1], $m[1]];
        }

        if (preg_match('/^\+(\d{6,15})$/', $number, $m)) {
            return [$m[1]];
        }

        return [];
    }
}
