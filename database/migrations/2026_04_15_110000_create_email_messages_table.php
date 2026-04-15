<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 minimal shape for inbound email storage.
 *
 * The InboundMailController stores raw RFC822 to MinIO and writes
 * a stub row here with `routing_status=pending`, then dispatches
 * ProcessInboundEmailJob on the `inbound-mail` queue. The job
 * parses the message, fills in header + body fields, and flips
 * `routing_status` to `routed`/`unrouted`/`failed`.
 *
 * Phase 2 will extend this with a `thread_id` FK once EmailThread
 * lands, and will stop allowing `team_id` to be null once the
 * InboundRouter resolves every stub to a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            // Team nullable in Phase 1 — webhook stubs are created
            // before the job resolves the tenant. Phase 2 tightens
            // this to NOT NULL once the router runs inline.
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            // Phase 2 adds thread_id FK. Leaving it off for now so
            // the migration is cleanly ahead of the EmailThread table.

            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');

            // Pointer into MinIO. Phase 1 = `s3://mail-raw/{ulid}.eml`.
            $table->string('raw_storage_path');

            // Parsed header fields. Nullable because the stub row
            // exists before the job parses anything.
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to_addresses')->nullable();
            $table->json('cc_addresses')->nullable();
            $table->string('subject', 1024)->nullable();

            // Parsed bodies — nullable for the same reason.
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            // Lifecycle. `pending` → job grabs it, parses, routes,
            // flips to `routed` / `unrouted` / `failed`.
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->enum('routing_status', ['pending', 'routed', 'unrouted', 'failed'])
                ->default('pending')
                ->index();

            // Extensibility: last error on failure, envelope copy,
            // whatever the router wants to stash for debugging.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
