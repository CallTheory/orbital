<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `display_order` to intake_flows for canvas-stable ordering of
 * channel trigger flows (so inbound_phone, email, sms, etc. always
 * render in the same left-to-right column at the top of the canvas).
 *
 * Also adds `kind` — future phases (action groups / action tables)
 * need to distinguish "a flow the canvas treats as a top-level node"
 * from "a flow that's a reusable shared chain." For Phase 1 every
 * existing row gets kind='call_flow'.
 *
 * `trigger_type` stays as a varchar; we just widen the set of
 * accepted values in application code. The new acceptable values
 * are: inbound_phone, inbound_email, inbound_sms, inbound_wctp,
 * outbound_phone, subflow, manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            if (! Schema::hasColumn('intake_flows', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('is_active');
            }
            if (! Schema::hasColumn('intake_flows', 'kind')) {
                $table->string('kind', 32)->default('call_flow')->after('trigger_type');
                $table->index('kind');
            }
        });

        // Widen `trigger_type` to a plain varchar so channel values
        // (inbound_phone, inbound_email, etc.) and `subflow` are all
        // accepted. Laravel's original `enum(...)` emitted a Postgres
        // CHECK constraint that rejects anything outside the original
        // four values.
        if (\DB::getDriverName() === 'pgsql') {
            \DB::statement('ALTER TABLE intake_flows DROP CONSTRAINT IF EXISTS intake_flows_trigger_type_check');
            \DB::statement('ALTER TABLE intake_flows ALTER COLUMN trigger_type TYPE varchar(32)');
        }
    }

    public function down(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            if (Schema::hasColumn('intake_flows', 'display_order')) {
                $table->dropColumn('display_order');
            }
            if (Schema::hasColumn('intake_flows', 'kind')) {
                $table->dropIndex(['kind']);
                $table->dropColumn('kind');
            }
        });
    }
};
