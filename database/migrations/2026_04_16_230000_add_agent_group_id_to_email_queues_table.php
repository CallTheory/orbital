<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_queues', function (Blueprint $table) {
            $table->foreignId('agent_group_id')
                ->nullable()
                ->after('overflow_agent_persona_id')
                ->constrained('agent_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_queues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_group_id');
        });
    }
};
