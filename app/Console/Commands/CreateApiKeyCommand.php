<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\TeamApiKey;
use Illuminate\Console\Command;

/**
 * `php artisan orbital:api-key {team} {name}` — issue a client API key.
 * Teams have no slug, so {team} is an id or an exact name.
 */
class CreateApiKeyCommand extends Command
{
    protected $signature = 'orbital:api-key {team : Client (team) id or exact name} {name : Label for the key, e.g. the integration it is for}';

    protected $description = 'Issue an API key for a client and print the token once';

    public function handle(): int
    {
        $ref = (string) $this->argument('team');

        $team = ctype_digit($ref)
            ? Team::find((int) $ref)
            : Team::where('name', $ref)->first();

        if ($team === null) {
            $this->error("No client found for '{$ref}'.");

            return self::FAILURE;
        }

        [$key, $token] = TeamApiKey::issue($team, (string) $this->argument('name'));

        $this->info("API key #{$key->id} created for {$team->name}. Copy it now; it cannot be shown again.");
        $this->line($token);

        return self::SUCCESS;
    }
}
