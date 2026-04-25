<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: named per-client web / REST endpoint configurations.
 *
 * Authors reference these by name from the `web_call` primitive.
 * Credentials (auth tokens, API keys, basic-auth passwords) are
 * encrypted at rest. Default headers (JSON) are merged with any the
 * step supplies at call time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_web_endpoints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('base_url');

            // none | basic | bearer | api_key | oauth2
            $table->string('auth_type', 32)->default('none');

            // Encrypted JSON blob whose shape depends on auth_type:
            //   basic   → {username, password}
            //   bearer  → {token}
            //   api_key → {header_name, key_value}
            //   oauth2  → {client_id, client_secret, token_url, scopes}
            $table->text('credentials')->nullable();

            $table->json('default_headers')->nullable();

            // Seconds the runtime waits before giving up on a call.
            $table->unsignedSmallInteger('timeout_seconds')->default(30);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'name']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_web_endpoints');
    }
};
