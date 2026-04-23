<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the three Orbital-specific "tenant_*" tables to "client_*".
 *
 * Customers in Orbital's vertical (answering services for property
 * management, legal, medical) already use the word "tenant" in their
 * own business — renters, clinic patients, etc. — so the original
 * multi-tenancy-inspired naming was a constant source of confusion
 * in UI copy and support conversations. We're standardizing on
 * "Client" which is the universal term for a call-center customer
 * account in the industry.
 *
 * Jetstream's own `teams` table and `team_id` foreign keys are
 * untouched — those are the upstream multi-tenancy scaffolding and
 * stay as-is so anyone familiar with Jetstream sees a familiar
 * schema. Only the Orbital-layer tables get renamed.
 */
return new class extends Migration
{
    /** @var array<string, string> from => to */
    protected array $renames = [
        'tenant_dids' => 'client_dids',
        'tenant_permission_grants' => 'client_permission_grants',
        'tenant_invitations' => 'client_invitations',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
