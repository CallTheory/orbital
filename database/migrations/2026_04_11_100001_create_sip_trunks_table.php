<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            // Nullable: null means platform-wide (shared across all tenants).
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->string('host');
            $table->unsignedInteger('port')->default(5060);
            $table->enum('transport', ['udp', 'tcp', 'tls'])->default('udp');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('auth_type')->default('userpass');
            $table->boolean('register')->default(false);
            $table->string('inbound_context')->default('from-trunk');
            $table->json('codecs')->nullable();
            $table->unsignedInteger('max_channels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_trunks');
    }
};
