<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single answer to "is this integration on?".
 *
 * Both the boot path and the settings UI ask here rather than reading
 * config directly, so "enabled" can never mean one thing to the
 * provider that installs the handler and another to the page that shows
 * an operator whether it is working.
 *
 * Enabled means the toggle is on AND there is somewhere to send to. A
 * toggle switched on with an empty DSN is a half-configured integration,
 * and treating it as on would produce a client that silently drops
 * everything — the worst of both states, because the operator believes
 * errors are being captured.
 */
class Observability
{
    public static function errorsEnabled(): bool
    {
        return (bool) config('observability.errors.enabled')
            && self::errorsDsn() !== null;
    }

    public static function errorsDsn(): ?string
    {
        $dsn = trim((string) config('observability.errors.dsn', ''));

        return $dsn === '' ? null : $dsn;
    }

    /**
     * Which deployment an event is attributed to. Falls back to the
     * Laravel environment, which is right for every install that isn't
     * pointing two deployments at one project.
     */
    public static function environment(): string
    {
        $configured = trim((string) config('observability.errors.environment', ''));

        return $configured !== '' ? $configured : (string) app()->environment();
    }
}
