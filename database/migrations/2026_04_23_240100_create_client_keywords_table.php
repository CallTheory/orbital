<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: per-client keyword dictionary.
 *
 * Authors tag calls with keywords from this list via the
 * `save_keyword` primitive; the reporting surface surfaces the tags
 * as filters. Keywords are cheap — typical clients maintain dozens
 * ("after_hours", "emergency", "spanish_speaker", "vip", ...) not
 * thousands.
 *
 * The pivot `call_log_keywords` lives alongside — that table is
 * added when `call_log` proper is extended (deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_keywords', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('slug', 64);  // machine-safe (after_hours, vip)
            $table->string('label', 120); // display (After Hours, VIP)
            $table->string('color', 16)->nullable(); // optional pill color

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_keywords');
    }
};
