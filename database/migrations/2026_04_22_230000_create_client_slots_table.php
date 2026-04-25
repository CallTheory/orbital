<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client slots — the declared catalog of named variables a client's
 * intake flows operate over.
 *
 * A slot is a typed placeholder for a value collected during the call
 * (caller_name, callback_phone, inquiry_type, etc.). Flows reference
 * slots by name; `gather_*` steps write to them, `save_message` reads
 * a subset of them, and transition conditions evaluate against them.
 *
 * Slots live at the client (team) level, not the flow level, because
 * multi-flow graphs pass values across flow boundaries. A slot set
 * in Flow A must still resolve when Flow B evaluates a transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('type', 32); // string | phone | email | number | boolean | date | choice
            $table->json('choices')->nullable(); // only used when type=choice
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_slots');
    }
};
