<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\AgentGroup;
use App\Models\AgentGroupMember;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds demo data for local development.
 *
 * Creates:
 *   - A "Demo Customer" tenant (the customer account)
 *   - A tenant_user contact for the customer portal
 *   - A platform-staff operator (takes calls for every tenant)
 *   - A platform-staff supervisor
 *   - A platform SIP trunk (shared, team_id null)
 *   - Operator WebRTC extension (team_id null, assigned to the operator user)
 *   - Demo Customer's AI agent persona + extension (team_id set)
 *   - Demo Customer's inbound routing rule + call queue (team_id set)
 *
 * Runs only in the local environment. Idempotent — exits if "Demo Customer"
 * tenant already exists.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (Team::where('name', 'Demo Customer')->exists()) {
            $this->command?->info('Demo customer tenant already exists, skipping.');
            return;
        }

        // ────────────────────────────────────────────────────────────
        // Platform staff (team-less roles) — take calls for ALL tenants
        // ────────────────────────────────────────────────────────────
        $operatorUser = User::firstOrCreate(
            ['email' => 'demo-operator@orbital.test'],
            ['name' => 'Demo Operator', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $supervisorUser = User::firstOrCreate(
            ['email' => 'demo-supervisor@orbital.test'],
            ['name' => 'Demo Supervisor', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $this->assignTeamlessRole($operatorUser, 'operator');
        $this->assignTeamlessRole($supervisorUser, 'supervisor');

        // ────────────────────────────────────────────────────────────
        // Platform-shared SIP trunk (team_id = null)
        // ────────────────────────────────────────────────────────────
        SipTrunk::firstOrCreate(
            ['name' => 'Platform Shared Trunk'],
            [
                'team_id' => null,
                'provider' => 'demo-provider',
                'host' => 'sip.demo.local',
                'port' => 5060,
                'transport' => 'udp',
                'username' => 'demo',
                'password' => 'changeme',
                'auth_type' => 'userpass',
                'register' => true,
                'inbound_context' => 'from-trunk',
                'codecs' => ['ulaw', 'alaw', 'g722'],
                'max_channels' => 10,
                'is_active' => true,
            ],
        );

        // Auto-allocate softphone extensions for the staff users.
        // The allocator stores the Extension and surfaces credentials on
        // the user's edit page in the Staff resource.
        $allocator = app(\App\Services\Telephony\PlatformExtensionAllocator::class);
        $allocator->ensureWebrtcExtensionFor($operatorUser);
        $allocator->ensureWebrtcExtensionFor($supervisorUser);

        // Sample hardware SIP phone — something a Polycom or Yealink could register to.
        Extension::firstOrCreate(
            ['number' => '100', 'team_id' => null],
            [
                'type' => 'sip_phone',
                'label' => 'Front desk Polycom (sample)',
                'sip_username' => 'frontdesk',
                'sip_password' => 'changeme',
                'transport' => 'udp',
                'context' => 'internal',
                'is_active' => true,
            ],
        );

        // ────────────────────────────────────────────────────────────
        // Demo Customer tenant (the customer account)
        // ────────────────────────────────────────────────────────────
        $tenantContact = User::firstOrCreate(
            ['email' => 'contact@democustomer.test'],
            [
                'name' => 'Demo Customer Contact',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $team = Team::forceCreate([
            'user_id' => $tenantContact->id,
            'name' => 'Demo Customer',
            'account_number' => 100001,
            'personal_team' => false,
            'max_users' => 10,
            'max_concurrent_calls' => 10,
        ]);

        $tenantContact->current_team_id = $team->id;
        $tenantContact->save();

        // Provisioner sets up the tenant_user role and makes the contact a tenant_user
        app(TenantProvisioner::class)->provision($team, $tenantContact);

        // ────────────────────────────────────────────────────────────
        // DIDs assigned to Demo Customer
        // Two-trunk failover pattern: primary + backup.
        // ────────────────────────────────────────────────────────────
        $sharedTrunk = SipTrunk::where('name', 'Platform Shared Trunk')->first();
        \App\Models\TenantDid::create([
            'team_id' => $team->id,
            'sip_trunk_id' => $sharedTrunk?->id,
            'number' => '+15551234567',
            'label' => 'Primary',
            'priority' => 0,
            'is_active' => true,
        ]);
        \App\Models\TenantDid::create([
            'team_id' => $team->id,
            'sip_trunk_id' => $sharedTrunk?->id,
            'number' => '+15559876543',
            'label' => 'Backup',
            'priority' => 10,
            'is_active' => true,
        ]);

        // ────────────────────────────────────────────────────────────
        // Demo Customer's tenant-scoped telephony config
        // ────────────────────────────────────────────────────────────
        $receptionist = AgentPersona::create([
            'team_id' => $team->id,
            'name' => 'Ava the Receptionist',
            'role' => 'AI front desk',
            'description' => 'Greets callers and routes them to the right person.',
            'system_prompt' => 'You are Ava, Demo Customer\'s AI receptionist. Greet callers warmly, find out why they are calling, and either answer basic questions or route them to a human operator.',
            'greeting' => 'Thanks for calling Demo Customer, this is Ava — how can I help?',
            'outbound_greeting' => 'Hi, this is Ava from Demo Customer, do you have a moment?',
            'personality' => 'Warm, efficient, brief. Two sentences max per turn.',
            'voice_id' => 'TX3LPaxmHKxFdv7VOQHJ',
            'llm_provider' => 'anthropic',
            'llm_model' => 'claude-sonnet-4-20250514',
            'stt_provider' => 'elevenlabs',
            'tts_provider' => 'elevenlabs',
            'is_active' => true,
        ]);

        Extension::create([
            'team_id' => $team->id,
            'number' => '1001',
            'type' => 'ai_agent',
            'label' => 'Demo Customer Receptionist',
            'assignable_type' => $receptionist->getMorphClass(),
            'assignable_id' => $receptionist->id,
            'context' => 'internal',
            'is_active' => true,
        ]);

        // The main public-facing number for Demo Customer (virtual extension)
        Extension::create([
            'team_id' => $team->id,
            'number' => '5551234567',
            'type' => 'virtual',
            'label' => 'Demo Customer Main DID',
            'context' => 'from-trunk',
            'is_active' => true,
        ]);

        // ────────────────────────────────────────────────────────────
        // Platform-level "All Operators" agent group containing both
        // demo platform staff users. Tenant queues ring this group.
        // ────────────────────────────────────────────────────────────
        $allOperators = AgentGroup::firstOrCreate(
            ['name' => 'all-operators'],
            [
                'label' => 'All Operators',
                'description' => 'Default pool of every platform operator and supervisor.',
                'is_active' => true,
            ],
        );

        foreach ([$operatorUser, $supervisorUser] as $i => $staff) {
            AgentGroupMember::firstOrCreate(
                [
                    'agent_group_id' => $allOperators->id,
                    'member_type' => $staff->getMorphClass(),
                    'member_id' => $staff->id,
                ],
                [
                    'priority' => $i,
                    'penalty' => 0,
                ],
            );
        }

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'demo-customer-main',
            'strategy' => 'ringall',
            'timeout' => 30,
            'retry' => 5,
            'wrapup_time' => 0,
            'max_callers' => 0,
            'music_on_hold' => 'default',
            'join_empty' => false,
            'leave_when_empty' => true,
            'overflow_agent_persona_id' => $receptionist->id,
            'agent_group_id' => $allOperators->id,
        ]);

        RoutingRule::create([
            'team_id' => $team->id,
            'name' => 'Demo Customer main inbound',
            'sip_trunk_id' => null,
            'match_type' => 'did',
            'match_pattern' => '_X.',
            'destination_type' => 'queue',
            'destination_id' => $queue->id,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->command?->info('Demo data seeded:');
        $this->command?->info('  Platform operator:   demo-operator@orbital.test / password');
        $this->command?->info('  Platform supervisor: demo-supervisor@orbital.test / password');
        $this->command?->info('  Tenant contact:      contact@democustomer.test / password');
    }

    /**
     * Assign a team-less platform role to a user.
     */
    protected function assignTeamlessRole(User $user, string $roleName): void
    {
        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $user->assignRole($roleName);
        } finally {
            $registrar->setPermissionsTeamId($original);
            $registrar->forgetCachedPermissions();
        }
    }
}
