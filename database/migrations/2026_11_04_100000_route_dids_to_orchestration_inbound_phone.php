<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DIDs can route straight to an orchestration's Inbound Phone channel.
 * The channel names the persona that answers (intake_flows.agent_persona_id),
 * and each call session records the flow it entered at, so post-call
 * actions and handoffs follow the path that call actually took.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('agent_persona_id')
                ->nullable()
                ->constrained('agent_personas')
                ->nullOnDelete();
        });

        Schema::table('call_session_states', function (Blueprint $table) {
            $table->unsignedBigInteger('entry_flow_id')->nullable();
        });

        // routing_rules.destination_type was created as an enum, which
        // Postgres enforces with a CHECK constraint, so a DID's rule
        // couldn't say 'orchestration'. (SQLite lost its copy of the
        // check when a later foreign key rebuilt the table.)
        $this->destinationTypeCheck(self::DESTINATION_TYPES);
    }

    private const OLD_DESTINATION_TYPES = ['extension', 'queue', 'agent', 'voicemail', 'ivr'];

    private const DESTINATION_TYPES = [...self::OLD_DESTINATION_TYPES, 'orchestration'];

    /** @param  list<string>  $types */
    private function destinationTypeCheck(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(fn (string $t) => "'{$t}'", $types));
        DB::statement('ALTER TABLE routing_rules DROP CONSTRAINT IF EXISTS routing_rules_destination_type_check');
        DB::statement("ALTER TABLE routing_rules ADD CONSTRAINT routing_rules_destination_type_check CHECK (destination_type IN ({$allowed}))");
    }

    public function down(): void
    {
        // Rules sent to an orchestration can't survive the narrower check.
        if (DB::getDriverName() === 'pgsql') {
            DB::table('routing_rules')->where('destination_type', 'orchestration')->delete();
        }
        $this->destinationTypeCheck(self::OLD_DESTINATION_TYPES);

        Schema::table('call_session_states', function (Blueprint $table) {
            $table->dropColumn('entry_flow_id');
        });

        Schema::table('intake_flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_persona_id');
        });
    }
};
