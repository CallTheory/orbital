<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exactly one row per (team, channel_type): "which flow graph runs
 * when this client's inbound phone / email / sms / wctp / outbound
 * channel fires?". Nullable `flow_graph_id` means the channel is
 * inert (no graph assigned).
 *
 * We auto-seed five rows per team on creation (see Team observer).
 * The backfill below covers every existing team by pointing each
 * assignment at that team's Default graph.
 *
 * Set-null on graph delete: deleting a flow graph doesn't destroy
 * the assignment row — it just goes inert. Authors then pick a
 * different active graph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_channel_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // inbound_phone | inbound_email | inbound_sms | inbound_wctp | outbound_phone
            $table->string('channel_type', 32);

            $table->foreignId('flow_graph_id')
                ->nullable()
                ->constrained('flow_graphs')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['team_id', 'channel_type']);
            $table->index('team_id');
        });

        // Backfill five rows per team, each pointing at the team's
        // Default graph (created by the preceding migration).
        $channelTypes = [
            'inbound_phone',
            'inbound_email',
            'inbound_sms',
            'inbound_wctp',
            'outbound_phone',
        ];

        $teams = DB::table('flow_graphs')
            ->where('name', 'Default')
            ->pluck('id', 'team_id');

        $now = now();
        $rows = [];
        foreach ($teams as $teamId => $graphId) {
            foreach ($channelTypes as $type) {
                $rows[] = [
                    'team_id' => $teamId,
                    'channel_type' => $type,
                    'flow_graph_id' => $graphId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($rows !== []) {
            DB::table('client_channel_assignments')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_channel_assignments');
    }
};
