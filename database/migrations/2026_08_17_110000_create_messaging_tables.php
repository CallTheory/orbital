<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging channel — persistence for SMS/MMS and the other
 * text-shaped transports.
 *
 * `message_queues` and `chat_queues` already existed (routing targets,
 * orchestration-aware) but nothing stored the conversations themselves,
 * so nothing could actually arrive. This is that missing half.
 *
 * Deliberately shaped like the email channel — thread + entries,
 * claim/release, queue assignment — rather than as its own model. An
 * operator working a client's traffic should not have to learn two
 * mental models for "a conversation someone started with us"; the
 * transport differs, the work does not.
 *
 * Where it differs from email, and why:
 *
 *   - Identity is a phone number/shortcode, not an address, and there's
 *     no Message-ID header to chain on. Threading is therefore
 *     (endpoint, remote address) within a rolling window, not RFC822
 *     header walking.
 *   - Delivery is asynchronous and reported later by the carrier, so
 *     entries carry a delivery status of their own — an email is "sent"
 *     when the SMTP server accepts it; an SMS is not delivered until the
 *     handset says so, and "sent but never delivered" is a real and
 *     common state that operators need to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Endpoints: the numbers/shortcodes we receive on.
        //
        // A client's DIDs already live in client_dids for voice. These
        // are deliberately separate: the same number can be voice-only,
        // SMS-only, or both, they're provisioned through a different
        // vendor relationship, and conflating them would mean a voice
        // DID change silently repointing text traffic.
        Schema::create('messaging_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // E.164 for phone numbers, bare digits for shortcodes, or a
            // pager/WCTP identifier. Stored exactly as the provider
            // reports it so inbound matching is a literal comparison.
            $table->string('address', 64);

            // sms | mms | rcs | smpp | wctp | paging — see
            // MessageQueue::PROTOCOLS.
            $table->string('protocol', 16)->default('sms');

            // Driver key from config/messaging.php (twilio, telnyx, log…).
            $table->string('provider', 32);

            // Per-endpoint provider settings: messaging service SID,
            // sender pool, per-number webhook secret. Encrypted — these
            // routinely carry credentials.
            $table->text('provider_config')->nullable();

            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One row per address per protocol: the same number can
            // legitimately carry SMS and MMS with different settings,
            // but never twice for the same protocol.
            $table->unique(['address', 'protocol']);
            $table->index(['team_id', 'is_active']);
        });

        // ── Threads: one conversation with one remote party.
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->foreignId('messaging_endpoint_id')
                ->nullable()
                ->constrained('messaging_endpoints')
                ->nullOnDelete();

            $table->foreignId('message_queue_id')
                ->nullable()
                ->constrained('message_queues')
                ->nullOnDelete();

            // The other party. Together with the endpoint this is the
            // thread's identity.
            $table->string('remote_address', 64);
            $table->string('protocol', 16)->default('sms');

            // Mirrors EmailThread::STATUS_* exactly. Operators move
            // between channels all day; the vocabulary has to match.
            $table->string('status', 32)->default('new');

            $table->foreignId('assigned_operator_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('assigned_agent_persona_id')
                ->nullable()
                ->constrained('agent_personas')
                ->nullOnDelete();

            // Fields collected across the conversation by the
            // orchestration runner — same shape as
            // call_session_states.fields, so the same compiled flow can
            // drive voice, chat, and messaging.
            $table->json('fields')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status', 'last_message_at']);
            // The threading lookup: "is there an open conversation with
            // this number on this endpoint?" — run on every inbound.
            $table->index(['messaging_endpoint_id', 'remote_address', 'status']);
        });

        // ── Entries: individual messages within a thread.
        Schema::create('message_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_thread_id')
                ->constrained('message_threads')
                ->cascadeOnDelete();

            // Denormalised from the thread so tenant-scoped queries and
            // per-client metrics don't need the join. Threads never move
            // between clients, so it can't drift.
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');

            $table->string('from_address', 64);
            $table->string('to_address', 64);
            $table->text('body')->nullable();

            // MMS/RCS attachments: [{url, content_type, size}]. Stored as
            // provider URLs on first write and rewritten to object-store
            // paths once fetched.
            $table->json('media')->nullable();

            // The provider's own id, for reconciling delivery receipts.
            // Unique where present: a redelivered webhook must update the
            // existing row rather than duplicate the conversation.
            $table->string('provider_message_id', 128)->nullable();
            $table->string('provider', 32)->nullable();

            // queued | sent | delivered | undelivered | failed | received
            $table->string('delivery_status', 32)->default('received');
            $table->text('delivery_error')->nullable();

            // Who sent it, for outbound. Exactly one of these is set —
            // the same AI-or-human split the call side records.
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_persona_id')->nullable()->constrained('agent_personas')->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_message_id']);
            $table->index(['message_thread_id', 'occurred_at']);
            $table->index(['team_id', 'direction', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_entries');
        Schema::dropIfExists('message_threads');
        Schema::dropIfExists('messaging_endpoints');
    }
};
