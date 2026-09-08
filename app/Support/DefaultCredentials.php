<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects credentials still sitting at the values `.env.example` ships.
 *
 * Those defaults exist so `sail up` gets a developer running in one
 * command. They become a liability the moment an install starts taking
 * real calls, and the failure is silent — nothing breaks, the door is
 * just open. SECURITY.md tells operators to check before going live, so
 * something has to actually check.
 *
 * Kept as a plain helper rather than folded into the status command so
 * it can be unit-tested and, later, surfaced on the system health
 * dashboard without duplicating the list.
 */
final class DefaultCredentials
{
    /**
     * Human label => the value `.env.example` ships.
     *
     * Add a row whenever `.env.example` grows a working default. A
     * default nobody knows about is worse than no default.
     *
     * @return array<string, string>
     */
    public static function shipped(): array
    {
        return [
            'Database password (DB_PASSWORD)' => 'password',
            'Icecast source password' => 'changeme',
            'Icecast admin password' => 'changeme',
            'Icecast relay password' => 'changeme',
            'Icecast password' => 'changeme',
            'pgAdmin password' => 'password',
        ];
    }

    /**
     * Current values, resolved the same way the app resolves them.
     *
     * @return array<string, mixed>
     */
    private static function current(): array
    {
        return [
            'Database password (DB_PASSWORD)' => config('database.connections.pgsql.password'),
            'Icecast source password' => env('ICECAST_SOURCE_PASSWORD'),
            'Icecast admin password' => env('ICECAST_ADMIN_PASSWORD'),
            'Icecast relay password' => env('ICECAST_RELAY_PASSWORD'),
            'Icecast password' => env('ICECAST_PASSWORD'),
            'pgAdmin password' => env('PGADMIN_DEFAULT_PASSWORD'),
        ];
    }

    /**
     * Labels of credentials still at their shipped default.
     *
     * Compares values rather than merely checking that something is set:
     * an operator who rotated four of five secrets needs to be told
     * about the fifth, not handed a clean bill of health.
     *
     * @return array<int, string>
     */
    public static function unrotated(): array
    {
        $current = self::current();
        $unrotated = [];

        foreach (self::shipped() as $label => $shipped) {
            $value = $current[$label] ?? null;

            if (is_string($value) && $value !== '' && hash_equals($shipped, $value)) {
                $unrotated[] = $label;
            }
        }

        return $unrotated;
    }
}
