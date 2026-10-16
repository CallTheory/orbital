<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_slots', function (Blueprint $table) {
            // Human-facing name shown to outside operators; `name` stays the machine key.
            $table->string('label')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('client_slots', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
