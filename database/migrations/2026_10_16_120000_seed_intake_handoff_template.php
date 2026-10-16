<?php

declare(strict_types=1);

use Database\Seeders\IntakeHandoffTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Installs that are already seeded get the shared intake → handoff
 * template here (DatabaseSeeder covers fresh ones). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new IntakeHandoffTemplateSeeder)->run();
    }

    public function down(): void
    {
        // Left in place: clients may have applied it.
    }
};
