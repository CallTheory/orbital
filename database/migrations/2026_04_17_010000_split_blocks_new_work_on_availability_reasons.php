<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_reasons', function (Blueprint $table) {
            $table->boolean('blocks_voice')->default(true)->after('blocks_new_work');
            $table->boolean('blocks_non_voice')->default(true)->after('blocks_voice');
        });

        // Migrate existing data: blocks_new_work=true → both blocked,
        // blocks_new_work=false → neither blocked.
        DB::table('availability_reasons')
            ->where('blocks_new_work', true)
            ->update(['blocks_voice' => true, 'blocks_non_voice' => true]);

        DB::table('availability_reasons')
            ->where('blocks_new_work', false)
            ->update(['blocks_voice' => false, 'blocks_non_voice' => false]);

        Schema::table('availability_reasons', function (Blueprint $table) {
            $table->dropColumn('blocks_new_work');
        });
    }

    public function down(): void
    {
        Schema::table('availability_reasons', function (Blueprint $table) {
            $table->boolean('blocks_new_work')->default(true)->after('dot_color');
        });

        // Collapse back: if either channel is blocked, blocks_new_work=true.
        DB::table('availability_reasons')
            ->where(function ($q) {
                $q->where('blocks_voice', true)
                    ->orWhere('blocks_non_voice', true);
            })
            ->update(['blocks_new_work' => true]);

        DB::table('availability_reasons')
            ->where('blocks_voice', false)
            ->where('blocks_non_voice', false)
            ->update(['blocks_new_work' => false]);

        Schema::table('availability_reasons', function (Blueprint $table) {
            $table->dropColumn(['blocks_voice', 'blocks_non_voice']);
        });
    }
};
