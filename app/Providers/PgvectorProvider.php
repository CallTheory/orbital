<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Pgvector\Laravel\Schema as PgvectorSchema;

/**
 * Slim replacement for Pgvector\Laravel\PgvectorServiceProvider.
 *
 * The upstream provider does two things: it registers the `vector`,
 * `halfvec`, `bit`, and `sparsevec` column-type macros on Laravel's
 * PostgresGrammar, AND it auto-loads a migration that unconditionally
 * runs `CREATE EXTENSION vector`. That second half blows up every
 * sqlite-backed feature test.
 *
 * We disable the upstream provider via composer's `dont-discover`
 * list and keep only the macro registration here. Our own migrations
 * (see 2026_04_11_100021_create_knowledge_chunks_table.php) guard
 * `CREATE EXTENSION vector` behind a driver check so they run on
 * sqlite and pgsql alike.
 */
class PgvectorProvider extends ServiceProvider
{
    public function boot(): void
    {
        PgvectorSchema::register();
    }
}
