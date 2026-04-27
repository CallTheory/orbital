<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-queue orchestration assignment.
 *
 * Each call queue and email queue carries its own `orchestration_id`
 * FK — that's how a queue picks which orchestration runs when one
 * of its DIDs (or matched email addresses) fires. Two queues for
 * the same client can run different orchestrations.
 *
 * Nullable: a queue without an orchestration assignment exists but
 * doesn't run any flow logic (operators can still pick up calls
 * via the queue's strategy). On orchestration delete, the FK is
 * set null so the queue stays valid; the channel just goes inert
 * until reassigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->foreignId('orchestration_id')
                ->nullable()
                ->after('team_id')
                ->constrained('orchestrations')
                ->nullOnDelete();
        });

        Schema::table('email_queues', function (Blueprint $table) {
            $table->foreignId('orchestration_id')
                ->nullable()
                ->after('team_id')
                ->constrained('orchestrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropForeign(['orchestration_id']);
            $table->dropColumn('orchestration_id');
        });
        Schema::table('email_queues', function (Blueprint $table) {
            $table->dropForeign(['orchestration_id']);
            $table->dropColumn('orchestration_id');
        });
    }
};
