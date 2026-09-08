<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client suppression list for text messaging.
 *
 * Requiring a carrier sender pool means Twilio already intercepts STOP
 * and refuses our outbound to that handset. This table exists anyway,
 * for three reasons:
 *
 *   1. The carrier's answer arrives as a REJECTED SEND. Without our own
 *      record an operator types a reply, watches it fail, and has no
 *      idea why — and the AI auto-reply path burns an LLM call per
 *      inbound to produce a message nobody can receive.
 *   2. Not every transport has carrier-side opt-out. An SMPP bind or a
 *      WCTP pager gateway has nothing of the kind, and the dev Log
 *      driver obviously doesn't either.
 *   3. The FCC's 2024 revocation rules read more broadly than one
 *      number: a revocation has to be honoured across the sender's
 *      traffic, not only on the number that happened to be texted.
 *      Carrier opt-out is scoped to the pool that received the STOP.
 *
 * Hence scope is the CLIENT, not the endpoint. `messaging_endpoint_id`
 * records where the request came from — provenance for the audit trail,
 * not the boundary it applies to. A customer who tells a client to stop
 * texting them has told that client, not one of its phone numbers.
 *
 * Opting back in (START/UNSTOP) clears the suppression by stamping
 * `opted_in_at` rather than deleting the row, so "did they ever opt out
 * and when" survives — which is the question that gets asked when a
 * complaint arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // Where the request arrived, for the audit trail. Nullable
            // and nullOnDelete: retiring a number must never silently
            // resurrect somebody's consent.
            $table->foreignId('messaging_endpoint_id')
                ->nullable()
                ->constrained('messaging_endpoints')
                ->nullOnDelete();

            // As the customer's handset presented it.
            $table->string('address', 64);

            // Normalised for matching — see MessagingOptOut::key(). The
            // same handset reaches us as +15551234567 and 5551234567
            // depending on the carrier and the day, and honouring a STOP
            // only when the spelling happens to match is the same as not
            // honouring it.
            $table->string('address_key', 64);

            // The word they actually sent (STOP, UNSUBSCRIBE, CANCEL…),
            // or a marker for an administrative entry.
            $table->string('keyword', 32)->nullable();

            // inbound | admin | provider
            $table->string('source', 16)->default('inbound');

            $table->timestamp('opted_out_at');

            // Set by START/UNSTOP. Null means the suppression is live.
            $table->timestamp('opted_in_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per customer per client, flipped in place. The
            // conversation entries carry the full STOP/START history;
            // this table only has to answer "may we text them right now"
            // fast enough to sit in front of every send.
            $table->unique(['team_id', 'address_key']);
            $table->index(['address_key', 'opted_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_opt_outs');
    }
};
