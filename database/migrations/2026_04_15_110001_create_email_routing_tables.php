<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of inbound email: tenant routing + threading.
 *
 * Adds the three domain tables the router needs
 * (`email_routing_rules`, `email_threads`, `email_attachments`)
 * and extends `email_messages` with a `thread_id` FK so the
 * router can group messages into conversations.
 *
 * All tenant-scoped via `team_id`. Matches the shape of the
 * call-side primitives (`RoutingRule`, `CallQueue`, etc.) but
 * kept as parallel tables because the semantics differ enough
 * that merging would force channel-type branches across the
 * telephony code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── email_routing_rules ──────────────────────────────
        // Match-then-route rules, priority ordered. The router
        // evaluates `function`-type rules first when the
        // recipient local-part has a `.function` suffix, then
        // falls through to `default` if nothing matched.
        //
        // `from_pattern` and `subject_pattern` can layer on top
        // of either path for sender- or subject-based routing.
        Schema::create('email_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');

            // Enum-as-string so we can grow it without a DB
            // migration. Allowed values enforced in the model.
            //
            //   function        — matches the `.function` suffix
            //                     of a `{acct}.{function}@...`
            //                     local-part (e.g. "alarms")
            //   from_pattern    — regex on the From: header
            //   subject_pattern — regex on the Subject: header
            //   default         — catch-all fallback per tenant
            $table->string('match_type', 32);
            $table->string('match_pattern')->nullable();

            //   queue          → email_queues.id (Phase 3)
            //   operator       → users.id (direct assignment)
            //   agent_persona  → agent_personas.id (AI handoff)
            //   discard        → drop the message (spam filter)
            $table->string('destination_type', 32);
            $table->unsignedBigInteger('destination_id')->nullable();

            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'is_active', 'priority']);
        });

        // ── email_threads ────────────────────────────────────
        // One row per conversation. Messages link to a thread
        // via `email_messages.thread_id`. The ThreadResolver
        // groups inbound messages by Message-ID / In-Reply-To /
        // References header chain, falling back to a sender +
        // subject match within a 7-day window (per tenant).
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // Subject with `Re:` / `Fwd:` prefixes stripped. Used
            // both as the display label and as the fallback key
            // when headers can't resolve a parent thread.
            $table->string('subject_root', 1024)->nullable();

            // All addresses that have ever appeared as from/to/cc
            // on any message in the thread. Useful for search and
            // for showing "Reply-all" targets.
            $table->json('participants')->nullable();

            //   new            — arrived, not yet worked
            //   in_progress    — operator or AI is handling it
            //   awaiting_reply — outbound sent, watching for reply
            //   closed         — handled, no further action needed
            $table->string('status', 32)->default('new');

            // Assignment — either an operator, an AI persona, or
            // neither (queue-routed, waiting for pickup).
            $table->foreignId('assigned_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_agent_persona_id')->nullable()->constrained('agent_personas')->nullOnDelete();

            // Which queue the router dropped it in (Phase 3 model).
            // Nullable because direct-to-operator and direct-to-
            // agent routes skip the queue entirely.
            $table->unsignedBigInteger('email_queue_id')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status', 'last_message_at']);
        });

        // ── email_attachments ────────────────────────────────
        // One row per attachment on an email_message. Actual
        // bytes live in MinIO; this table is just the index.
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->string('filename', 512)->nullable();
            $table->string('content_type', 255)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('storage_path', 1024);
            $table->boolean('inline')->default(false);
            // `cid:` reference used to inline the attachment
            // into the html body (e.g. embedded image). Null
            // for regular file attachments.
            $table->string('content_id', 255)->nullable();
            $table->timestamps();

            $table->index('email_message_id');
        });

        // ── email_messages.thread_id ─────────────────────────
        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreignId('thread_id')
                ->nullable()
                ->after('team_id')
                ->constrained('email_threads')
                ->nullOnDelete();
            $table->index(['thread_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropIndex(['thread_id', 'received_at']);
            $table->dropColumn('thread_id');
        });
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_threads');
        Schema::dropIfExists('email_routing_rules');
    }
};
