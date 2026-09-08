<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DefaultCredentials;
use Tests\TestCase;

/**
 * SECURITY.md tells operators to run `orbital:status` before taking real
 * calls and promises it will flag credentials still at their shipped
 * defaults. A promise like that has to be true, and it silently stops
 * being true the moment someone adds a default to `.env.example` and
 * forgets the list here.
 */
class DefaultCredentialsTest extends TestCase
{
    public function test_a_shipped_default_is_reported(): void
    {
        config()->set('database.connections.pgsql.password', 'password');

        $this->assertContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_a_rotated_credential_is_not_reported(): void
    {
        config()->set('database.connections.pgsql.password', 'a-real-secret');

        $this->assertNotContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_an_unset_credential_is_not_reported(): void
    {
        // Not configured at all is a different situation from
        // "configured to the value we shipped", and only the second one
        // is a finding.
        config()->set('database.connections.pgsql.password', null);

        $this->assertNotContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_every_shipped_default_in_env_example_is_covered(): void
    {
        // The list is maintained by hand, so this asserts it hasn't
        // drifted from the file it describes. A default nobody knows
        // about is worse than no default.
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($envExample);

        // Every literal default value we claim to know about should
        // still appear in .env.example. If one is gone, the entry here
        // is stale; if a new one appeared, add it.
        foreach (array_unique(DefaultCredentials::shipped()) as $value) {
            $this->assertStringContainsString(
                '='.$value,
                $envExample,
                "DefaultCredentials lists '{$value}' but .env.example no longer ships it.",
            );
        }
    }
}
