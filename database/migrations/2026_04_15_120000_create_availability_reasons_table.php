<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level vocabulary for operator availability states.
 * Each row is one entry in the availability selector dropdown,
 * including the `available` row itself — which is seeded with
 * slug='available', blocks_new_work=false, and is protected
 * from deletion by the model's `deleting` hook. There's no
 * "implicit" state anymore; everything lives in this table.
 *
 * Managed by super-admins under Features → Availability Reasons.
 * Seeded with a starter set (Available, On break, In meeting,
 * Lunch, Training, Offline) that the platform operator can
 * edit, reorder, disable, or augment to match their workflow.
 * The Available row specifically can be renamed and recolored
 * but not deleted or marked inactive — the resource UI locks
 * those fields and the model rejects deletion as a safety net.
 *
 * `slug` is the stable key stored on `users.availability_status`,
 * auto-generated on create and hidden from the admin form.
 * The Available row's slug is the literal `available` constant
 * (AvailabilityReason::AVAILABLE) so code paths have a canonical
 * reference regardless of what the admin renames the label to.
 *
 * `dot_color` holds an arbitrary hex color that the operator
 * picks via a color picker in the admin form. The AvailabilitySelector
 * renders it as an inline background style on the status pill's
 * dot, so any color works — there's no fixed palette.
 *
 * `blocks_new_work` is the one thing routing actually cares about:
 * when an operator is on this status, should they still receive
 * new call / email assignments? Defaults to true because most
 * "I'm not available" reasons should mute routing. A handful of
 * soft statuses (e.g. "Back in 5") could set it to false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_reasons', function (Blueprint $table) {
            $table->id();
            // Stable lookup key, auto-slugged from label on create
            // and never exposed in the admin UI. Hidden because
            // there's no safe way to let admins edit it — renaming
            // would strand operators currently on that status.
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            // Hex color (e.g. "#f59e0b") picked by the admin via
            // a color picker. Stored wide enough for any CSS color
            // spelling — "#fff", "#f59e0b", "rgb(245, 158, 11)".
            $table->string('dot_color', 32)->default('#f59e0b');
            // Routing behavior — THE thing that actually matters
            // to call + email distribution. True means the
            // operator DOES NOT receive new work while on this
            // status. False means they still do (rare — e.g. a
            // "Back in 5" soft pause that shouldn't actually
            // stop routing).
            $table->boolean('blocks_new_work')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_reasons');
    }
};
