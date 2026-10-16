<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client API keys for the public (v1) API. Only the sha256 of the
 * token is stored; the plain token is shown once at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_api_keys');
    }
};
