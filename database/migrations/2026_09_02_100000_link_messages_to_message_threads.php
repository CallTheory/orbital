<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an answering-service message record which text conversation it
 * came out of.
 *
 * `messages` already carries `call_log_id` for the voice channel. The
 * messaging channel had no equivalent, which meant a message taken from
 * an SMS conversation arrived in the client's portal with no way back
 * to the exchange that produced it — and no way for the operator UI to
 * tell that a message had already been taken from a thread, so the same
 * conversation could be written up twice.
 *
 * Nullable because most messages have no thread: the voice path, the
 * AI session writer, and an operator taking a message from a live call
 * all leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('message_thread_id')
                ->nullable()
                ->after('call_log_id')
                ->constrained()
                ->nullOnDelete();

            // Answers "has this conversation already been written up?"
            // on every render of the thread view.
            $table->index('message_thread_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['message_thread_id']);
            $table->dropConstrainedForeignId('message_thread_id');
        });
    }
};
