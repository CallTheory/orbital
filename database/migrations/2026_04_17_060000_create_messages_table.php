<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_persona_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('caller_name');
            $table->string('caller_phone')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('new');
            $table->string('urgency', 20)->default('normal');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
