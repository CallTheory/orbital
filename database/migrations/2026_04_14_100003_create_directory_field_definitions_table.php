<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-defined schema for the Directory page. Parallel to
 * contact_field_definitions but intentionally a separate table —
 * directory routing needs a different vocabulary and the two lists
 * don't benefit from sharing a pool (same argument as contact_tags
 * vs directory_tags).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type', 32);
            $table->jsonb('options')->nullable();
            $table->string('role', 32)->default('none');
            $table->boolean('required')->default(false);
            $table->string('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'key']);
            $table->index(['team_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_field_definitions');
    }
};
