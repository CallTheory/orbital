<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the per-client Spatie roles from `tenant_admin` / `tenant_user`
 * to `client_admin` / `client_user` across the whole roles table.
 *
 * These roles are team-scoped (one pair per team_id), so the update
 * is a blanket match-by-name — every existing role row flips to the
 * new name. The role.id values stay the same so existing
 * `model_has_roles` assignments continue to work without a touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->where('name', 'tenant_admin')->update(['name' => 'client_admin']);
        DB::table('roles')->where('name', 'tenant_user')->update(['name' => 'client_user']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->where('name', 'client_admin')->update(['name' => 'tenant_admin']);
        DB::table('roles')->where('name', 'client_user')->update(['name' => 'tenant_user']);
    }
};
