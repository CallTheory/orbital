<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the pgvector extension is enabled on the primary database.
 * Our migrations already create it conditionally based on the driver,
 * but this bootstrapper is what the System Setup page shows — it
 * provides a report that "pgvector is ready" (or isn't) and can
 * re-run CREATE EXTENSION idempotently.
 */
class PgvectorBootstrapper implements Bootstrapper
{
    public function key(): string
    {
        return 'pgvector';
    }

    public function name(): string
    {
        return 'PostgreSQL pgvector extension';
    }

    public function description(): string
    {
        return 'Vector column support for knowledge-store embeddings and similarity search.';
    }

    public function icon(): string
    {
        return 'heroicon-o-circle-stack';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function status(): BootstrapReport
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: 'Non-pgsql connection — pgvector is only available on PostgreSQL.',
            );
        }

        $installed = $this->extensionInstalled();

        return new BootstrapReport(
            status: $installed ? BootstrapStatus::Installed : BootstrapStatus::Missing,
            message: $installed
                ? 'Vector extension is active.'
                : 'pgvector extension is not yet enabled on this database.',
            steps: [
                ['label' => 'Extension available', 'ok' => $installed, 'detail' => null],
            ],
        );
    }

    public function install(): BootstrapReport
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: 'Cannot install pgvector on a non-pgsql connection.',
            );
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        return $this->status();
    }

    protected function extensionInstalled(): bool
    {
        $row = DB::selectOne("SELECT 1 AS found FROM pg_extension WHERE extname = 'vector'");
        return (bool) ($row->found ?? false);
    }
}
