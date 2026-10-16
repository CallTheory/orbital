<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orchestrations', function (Blueprint $table) {
            // List of {name,label,type,choices,description}; created on the
            // client when the orchestration is duplicated into a team.
            $table->json('default_slots')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orchestrations', function (Blueprint $table) {
            $table->dropColumn('default_slots');
        });
    }
};
