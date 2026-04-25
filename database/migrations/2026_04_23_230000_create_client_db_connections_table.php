<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: named per-client database connections.
 *
 * Flow authors reference these by name (`connection_name` param on
 * db_* primitives). Credentials are encrypted at rest via the
 * `encrypted` cast on the model.
 *
 * `driver` is a varchar (not enum) so we can add new drivers without
 * a migration. Accepted values — postgres, mysql, mssql, oracle,
 * sqlite, odbc, rest_api — are enforced by the model's constant
 * list + a Filament rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_db_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('driver', 32);

            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('database')->nullable();
            $table->string('schema')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted

            // Driver-specific options (ssl mode, timeout, dsn fragments,
            // REST base path / auth config, etc.).
            $table->json('options')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'name']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_db_connections');
    }
};
