<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Registers or rotates a Passport OAuth2 client used by one of the
 * downstream control panels (pgAdmin, MinIO Console, future tools).
 *
 *     php artisan orbital:create-oidc-client {name} {redirect}
 *
 * Idempotent by `name`: if a client with the given name already
 * exists, we delete it and recreate with the same name + new secret.
 * Passport doesn't expose a rotate-secret API so delete-and-recreate
 * is the standard pattern.
 *
 * Prints `CLIENT_ID=<id>` and `CLIENT_SECRET=<secret>` to stdout —
 * SsoSecretsBootstrapper captures the output and writes the values
 * to `.env` under the configured prefix. Parsed by line so the
 * command's own human-friendly chatter (colors / success messages)
 * doesn't interfere.
 *
 * The created client is always an authorization-code grant client
 * with a secret (not PKCE-public) because our downstream tools run
 * as server-side apps that can safely store a client secret.
 */
class CreateOidcClient extends Command
{
    protected $signature = 'orbital:create-oidc-client {name : Stable client name (e.g. pgadmin)} {redirect : OAuth2 callback URL the downstream app expects}';

    protected $description = 'Create or rotate a Passport OAuth2 client for OIDC SSO against a downstream tool.';

    public function handle(ClientRepository $clients): int
    {
        $name = (string) $this->argument('name');
        $redirect = (string) $this->argument('redirect');

        // Rotation-by-recreate: Passport's client secret is hashed
        // at rest, so there's no way to "re-display" an existing
        // client's plaintext secret. Deleting and recreating is the
        // least-surprising path.
        $existing = Client::query()->where('name', $name)->first();
        if ($existing) {
            $existing->delete();
        }

        // createAuthorizationCodeGrantClient signature in Passport 13:
        //   (name, redirectUris, confidential, user, enableDeviceFlow).
        // `confidential: true` gives the client a secret — required
        // because our downstream tools are server-side and can safely
        // store one.
        $client = $clients->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: [$redirect],
            confidential: true,
        );

        // The plainSecret attribute is only populated on the model
        // returned directly from create — it's stripped from any
        // subsequent fetches. Capture it now.
        $plainSecret = $client->plainSecret ?? '';

        $this->line("CLIENT_ID={$client->id}");
        $this->line("CLIENT_SECRET={$plainSecret}");

        return self::SUCCESS;
    }
}
