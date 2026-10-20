<?php

declare(strict_types=1);

use Database\Seeders\IntakeHandoffTemplateSeeder;
use Database\Seeders\IntakeMessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeded installs get the take-a-message template, and the handoff
 * template seeded before it gets its Inbound Phone channel and canvas
 * position (DatabaseSeeder covers fresh installs). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new IntakeHandoffTemplateSeeder)->run();
        (new IntakeMessageTemplateSeeder)->run();
    }

    public function down(): void
    {
        // Left in place: clients may have applied them.
    }
};
