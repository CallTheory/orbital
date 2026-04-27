<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentGroup;
use App\Models\AgentGroupMember;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\ClientDid;
use App\Models\ClientSlot;
use App\Models\EmailQueue;
use App\Models\EmailRoutingRule;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeStore;
use App\Models\Orchestration;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Services\Flows\ChannelTriggerSeeder;
use App\Services\Knowledge\OllamaEmbedder;
use App\Services\Telephony\PlatformExtensionAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds demo data for local development.
 *
 * Creates:
 *   - A "Demo Customer" tenant (the customer account)
 *   - A client_user contact for the customer portal
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
class DemoClientSeeder extends Seeder
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
        $allocator = app(PlatformExtensionAllocator::class);
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

        // Provisioner sets up the client_user role and makes the contact a client_user
        app(ClientProvisioner::class)->provision($team, $tenantContact);

        // ────────────────────────────────────────────────────────────
        // DIDs assigned to Demo Customer
        // Two-trunk failover pattern: primary + backup.
        // ────────────────────────────────────────────────────────────
        $sharedTrunk = SipTrunk::where('name', 'Platform Shared Trunk')->first();
        ClientDid::create([
            'team_id' => $team->id,
            'sip_trunk_id' => $sharedTrunk?->id,
            'number' => '+15551234567',
            'label' => 'Primary',
            'priority' => 0,
            'is_active' => true,
        ]);
        ClientDid::create([
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
            'system_prompt' => implode("\n", [
                'You are Ava, Demo Customer\'s AI receptionist.',
                '',
                'RULES:',
                '- NEVER make up names, phone numbers, employee names, or any information that is not in your knowledge base.',
                '- If you cannot answer a question from your knowledge base, say so honestly and offer to take a message.',
                '- When taking a message, you MUST collect: the caller\'s full name, their phone number, and the reason they are calling.',
                '- Keep responses brief — two sentences max per turn.',
                '- Be warm, professional, and efficient.',
            ]),
            'greeting' => 'Thanks for calling Demo Customer, this is Ava — how can I help you today?',
            'outbound_greeting' => 'Hi, this is Ava from Demo Customer, do you have a moment?',
            'personality' => 'Warm, efficient, brief. Two sentences max per turn. Never fabricate information.',
            'voice_id' => '21m00Tcm4TlvDq8ikWAM',
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
        // demo platform staff users. Client queues ring this group.
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

        // ────────────────────────────────────────────────────────────
        // Demo Customer email queue + catch-all routing rule
        // ────────────────────────────────────────────────────────────
        $emailQueue = EmailQueue::create([
            'team_id' => $team->id,
            'name' => 'General Inbox',
            'description' => 'Default email queue — all inbound email for this tenant lands here.',
            'strategy' => EmailQueue::STRATEGY_MANUAL,
            'agent_group_id' => $allOperators->id,
            'is_active' => true,
        ]);

        EmailRoutingRule::create([
            'team_id' => $team->id,
            'name' => 'Default catch-all',
            'match_type' => EmailRoutingRule::MATCH_DEFAULT,
            'destination_type' => EmailRoutingRule::DESTINATION_QUEUE,
            'destination_id' => $emailQueue->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        // ────────────────────────────────────────────────────────────
        // Knowledge store — FAQ for Demo Customer
        // ────────────────────────────────────────────────────────────
        $faqStore = KnowledgeStore::firstOrCreate(
            ['team_id' => $team->id, 'name' => 'Demo Customer FAQ'],
            [
                'description' => 'Frequently asked questions about Demo Customer services.',
                'embedding_model' => 'ollama:nomic-embed-text',
                'embedding_dims' => 768,
                'ingest_status' => 'idle',
                'is_active' => true,
            ],
        );

        $faqs = [
            'Demo Customer is open Monday through Friday, 8:00 AM to 6:00 PM Eastern Time. We are closed on weekends and major holidays.',
            'Demo Customer is located at 123 Main Street, Suite 200, Anytown, USA 12345. Free parking is available in the rear lot.',
            'Demo Customer offers consulting services, project management, and technical support for small to medium businesses.',
            'For billing questions, you can reach the billing department during regular business hours. We accept all major credit cards, checks, and ACH transfers. Invoices are sent on the 1st of each month with net-30 payment terms.',
            'To schedule an appointment, please call during business hours and our receptionist will find a time that works for you. Same-day appointments are available when possible.',
            'Our team includes specialists in IT consulting, business strategy, and customer support. We do not provide legal, medical, or financial advice.',
            'For urgent after-hours issues, please leave a detailed voicemail and we will return your call first thing the next business day.',
            'Demo Customer has been serving the community since 2010. We pride ourselves on responsive service and building lasting relationships with our clients.',
        ];

        if ($faqStore->chunks()->count() === 0) {
            try {
                $embedder = app(OllamaEmbedder::class);
                foreach ($faqs as $i => $faq) {
                    $embedding = $embedder->embed($faq);
                    KnowledgeChunk::create([
                        'store_id' => $faqStore->id,
                        'source_type' => 'text',
                        'source_ref' => 'faq-seed',
                        'chunk_index' => $i,
                        'content' => $faq,
                        'embedding' => json_encode($embedding),
                        'metadata' => ['seeded' => true],
                    ]);
                }
                $faqStore->update(['chunk_count' => count($faqs)]);
                $this->command?->info('  Embedded '.count($faqs).' FAQ chunks via Ollama');
            } catch (\Throwable $e) {
                $this->command?->warn('  Skipped FAQ embeddings (Ollama unavailable): '.$e->getMessage());
                // Still create chunks without embeddings
                foreach ($faqs as $i => $faq) {
                    KnowledgeChunk::create([
                        'store_id' => $faqStore->id,
                        'source_type' => 'text',
                        'source_ref' => 'faq-seed',
                        'chunk_index' => $i,
                        'content' => $faq,
                        'metadata' => ['seeded' => true],
                    ]);
                }
                $faqStore->update(['chunk_count' => count($faqs)]);
            }
        }

        // ────────────────────────────────────────────────────────────
        // Variables (client_slots) — the typed catalog every flow
        // operates over. Declared once at the client level.
        // ────────────────────────────────────────────────────────────
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'caller_name'], ['type' => 'string', 'description' => "The caller's full name"]);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'callback_phone'], ['type' => 'phone',  'description' => 'A number to reach them back on']);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'inquiry_type'], ['type' => 'choice', 'choices' => ['maintenance', 'leasing', 'other'], 'description' => 'Why the caller reached out']);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'property_address'], ['type' => 'string', 'description' => 'Maintenance property address']);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'issue_description'], ['type' => 'string', 'description' => 'What needs fixing']);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'lease_interest'], ['type' => 'string', 'description' => 'What kind of unit they\'re looking for']);
        ClientSlot::updateOrCreate(['team_id' => $team->id, 'name' => 'reason'], ['type' => 'string', 'description' => 'Generic reason for calling']);

        // ────────────────────────────────────────────────────────────
        // Flow graph — the Inbound Phone channel trigger wires into
        // Greet & Route, which branches to three per-inquiry flows.
        //
        //   Inbound Phone (trigger, active) ─→ Greet & Route ─┬─ Maintenance Message
        //                                                      ├─ Leasing Message
        //                                                      └─ General Message
        //
        // The ChannelTriggerSeeder creates the five trigger flows
        // when the editor first opens; here we just activate the
        // phone one and wire it into the demo's subflows. Canvas
        // positions for subflows are seeded so the demo opens laid
        // out sensibly.
        // ────────────────────────────────────────────────────────────
        app(ChannelTriggerSeeder::class)->ensureTriggersFor($team);
        $orchestrationId = Orchestration::where('team_id', $team->id)->where('name', 'Default')->value('id');

        // Assign the Default orchestration to the demo's main call
        // queue so inbound calls routed by call_queue_dids pick up
        // the canvas's flow logic.
        $queue->update(['orchestration_id' => $orchestrationId]);

        $byKey = IntakeGoal::whereIn('key', [
            'answer_question',
            'gather_text',
            'gather_phone',
            'gather_choice',
            'save_message',
            'trigger_inbound_phone',
        ])->get()->keyBy('key');

        $inboundPhone = IntakeFlow::where('team_id', $team->id)
            ->where('trigger_type', IntakeFlow::TRIGGER_INBOUND_PHONE)
            ->first();
        $inboundPhone->update(['is_active' => true, 'description' => 'Inbound calls on Demo Customer\'s DIDs.']);

        // Populate the trigger flow with a descriptor step so the
        // synchronizer knows which DIDs / pattern to match.
        IntakeFlowStep::firstOrCreate(
            ['flow_id' => $inboundPhone->id, 'intake_goal_id' => $byKey['trigger_inbound_phone']->id],
            ['position' => 0, 'step_params' => [
                // Empty did_ids / pattern means "match any DID
                // assigned to this client" — the synchronizer will
                // emit a routing rule per active ClientDid.
            ]],
        );

        // Top-down layout: Greet & Route below the trigger row, then
        // three branches in a row beneath it.
        $greet = IntakeFlow::create(['team_id' => $team->id, 'orchestration_id' => $orchestrationId, 'name' => 'Greet & Route',       'description' => 'Identify the caller\'s reason and route to the matching branch.', 'is_active' => true, 'canvas_x' => 340, 'canvas_y' => 260]);
        $maint = IntakeFlow::create(['team_id' => $team->id, 'orchestration_id' => $orchestrationId, 'name' => 'Maintenance Message', 'description' => 'Collect property + issue details and save a maintenance message.', 'is_active' => true, 'canvas_x' => 20,  'canvas_y' => 620]);
        $leasing = IntakeFlow::create(['team_id' => $team->id, 'orchestration_id' => $orchestrationId, 'name' => 'Leasing Message',     'description' => 'Collect leasing interest details and save a leasing message.',    'is_active' => true, 'canvas_x' => 340, 'canvas_y' => 620]);
        $general = IntakeFlow::create(['team_id' => $team->id, 'orchestration_id' => $orchestrationId, 'name' => 'General Message',     'description' => 'Catch-all message flow for any other reason.',                    'is_active' => true, 'canvas_x' => 660, 'canvas_y' => 620]);

        // Greet & Route steps
        IntakeFlowStep::create(['flow_id' => $greet->id, 'intake_goal_id' => $byKey['answer_question']->id, 'position' => 0, 'step_params' => ['knowledge_store_ids' => [$faqStore->id]]]);
        IntakeFlowStep::create(['flow_id' => $greet->id, 'intake_goal_id' => $byKey['gather_choice']->id,   'position' => 1, 'step_params' => ['slot' => 'inquiry_type', 'label' => 'Reason for Call', 'options' => ['maintenance', 'leasing', 'other'], 'required' => true]]);

        // Maintenance Message steps
        IntakeFlowStep::create(['flow_id' => $maint->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 0, 'step_params' => ['slot' => 'caller_name',       'label' => 'Caller Name',      'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $maint->id, 'intake_goal_id' => $byKey['gather_phone']->id,  'position' => 1, 'step_params' => ['slot' => 'callback_phone',    'label' => 'Callback Phone',   'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $maint->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 2, 'step_params' => ['slot' => 'property_address',  'label' => 'Property Address', 'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $maint->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 3, 'step_params' => ['slot' => 'issue_description', 'label' => 'What\'s wrong',    'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $maint->id, 'intake_goal_id' => $byKey['save_message']->id,  'position' => 4, 'step_params' => ['include_slots' => ['caller_name', 'callback_phone', 'property_address', 'issue_description'], 'destination' => 'inbox']]);

        // Leasing Message steps
        IntakeFlowStep::create(['flow_id' => $leasing->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 0, 'step_params' => ['slot' => 'caller_name',    'label' => 'Caller Name',     'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $leasing->id, 'intake_goal_id' => $byKey['gather_phone']->id,  'position' => 1, 'step_params' => ['slot' => 'callback_phone', 'label' => 'Callback Phone',  'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $leasing->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 2, 'step_params' => ['slot' => 'lease_interest', 'label' => 'Lease Interest',  'required' => true, 'hint' => 'e.g. 2BR, by June']]);
        IntakeFlowStep::create(['flow_id' => $leasing->id, 'intake_goal_id' => $byKey['save_message']->id,  'position' => 3, 'step_params' => ['include_slots' => ['caller_name', 'callback_phone', 'lease_interest'], 'destination' => 'inbox']]);

        // General Message steps
        IntakeFlowStep::create(['flow_id' => $general->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 0, 'step_params' => ['slot' => 'caller_name',    'label' => 'Caller Name',     'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $general->id, 'intake_goal_id' => $byKey['gather_phone']->id,  'position' => 1, 'step_params' => ['slot' => 'callback_phone', 'label' => 'Callback Phone',  'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $general->id, 'intake_goal_id' => $byKey['gather_text']->id,   'position' => 2, 'step_params' => ['slot' => 'reason',         'label' => 'Reason',          'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $general->id, 'intake_goal_id' => $byKey['save_message']->id,  'position' => 3, 'step_params' => ['include_slots' => ['caller_name', 'callback_phone', 'reason'], 'destination' => 'inbox']]);

        // Inbound Phone (trigger) -> Greet & Route
        IntakeFlowTransition::create(['from_flow_id' => $inboundPhone->id, 'to_flow_id' => $greet->id, 'priority' => 10, 'description' => 'matched', 'condition' => null]);

        // Transitions out of Greet & Route
        IntakeFlowTransition::create(['from_flow_id' => $greet->id, 'to_flow_id' => $maint->id,   'priority' => 10,  'description' => 'maintenance',  'condition' => ['==' => [['var' => 'slots.inquiry_type'], 'maintenance']]]);
        IntakeFlowTransition::create(['from_flow_id' => $greet->id, 'to_flow_id' => $leasing->id, 'priority' => 20,  'description' => 'leasing',      'condition' => ['==' => [['var' => 'slots.inquiry_type'], 'leasing']]]);
        IntakeFlowTransition::create(['from_flow_id' => $greet->id, 'to_flow_id' => $general->id, 'priority' => 30,  'description' => 'other',        'condition' => ['==' => [['var' => 'slots.inquiry_type'], 'other']]]);

        // Link the entry flow to Ava's persona
        $receptionist->update(['default_flow_id' => $greet->id]);

        // Route the demo's DIDs to the main call queue via the new
        // call_queue_dids pivot — replaces the old
        // synthetic routing_rules.
        $demoCallQueue = CallQueue::where('team_id', $team->id)->first();
        if ($demoCallQueue) {
            $didIds = ClientDid::where('team_id', $team->id)
                ->where('is_active', true)
                ->pluck('id');
            $demoCallQueue->dids()->sync($didIds);
        }

        // ────────────────────────────────────────────────────────────
        // Add the super-admin (user 1) to the all-operators group so
        // they can see queued email threads during development.
        // ────────────────────────────────────────────────────────────
        $admin = User::find(1);
        if ($admin) {
            AgentGroupMember::firstOrCreate(
                [
                    'agent_group_id' => $allOperators->id,
                    'member_type' => $admin->getMorphClass(),
                    'member_id' => $admin->id,
                ],
                ['priority' => 0, 'penalty' => 0],
            );
        }

        // ────────────────────────────────────────────────────────────
        // Acme Corp — second tenant with advanced email routing
        //
        // Demonstrates multi-queue routing: VIP, urgent, and default
        // queues with function suffix, subject regex, and catch-all
        // rules at different priorities.
        // ────────────────────────────────────────────────────────────
        if (! Team::where('name', 'Acme Corp')->exists()) {
            $acmeContact = User::firstOrCreate(
                ['email' => 'contact@acmecorp.test'],
                [
                    'name' => 'Acme Corp Contact',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $acmeTeam = Team::forceCreate([
                'user_id' => $acmeContact->id,
                'name' => 'Acme Corp',
                'account_number' => 100002,
                'personal_team' => false,
                'timezone' => 'America/Chicago',
                'max_users' => 5,
                'max_concurrent_calls' => 5,
            ]);

            $acmeContact->current_team_id = $acmeTeam->id;
            $acmeContact->save();
            app(ClientProvisioner::class)->provision($acmeTeam, $acmeContact);

            // Three email queues with different operator groups
            $acmeVipQueue = EmailQueue::create([
                'team_id' => $acmeTeam->id,
                'name' => 'VIP',
                'description' => 'High-priority clients — fast response expected.',
                'strategy' => EmailQueue::STRATEGY_MANUAL,
                'agent_group_id' => $allOperators->id,
                'is_active' => true,
            ]);

            $acmeUrgentQueue = EmailQueue::create([
                'team_id' => $acmeTeam->id,
                'name' => 'Urgent',
                'description' => 'Emails flagged by subject keywords.',
                'strategy' => EmailQueue::STRATEGY_MANUAL,
                'agent_group_id' => $allOperators->id,
                'is_active' => true,
            ]);

            $acmeDefaultQueue = EmailQueue::create([
                'team_id' => $acmeTeam->id,
                'name' => 'General',
                'description' => 'Everything else.',
                'strategy' => EmailQueue::STRATEGY_MANUAL,
                'agent_group_id' => $allOperators->id,
                'is_active' => true,
            ]);

            // Function suffix: 100002.vip@... → VIP queue
            EmailRoutingRule::create([
                'team_id' => $acmeTeam->id,
                'name' => 'VIP function suffix',
                'match_type' => EmailRoutingRule::MATCH_FUNCTION,
                'match_pattern' => 'vip',
                'destination_type' => EmailRoutingRule::DESTINATION_QUEUE,
                'destination_id' => $acmeVipQueue->id,
                'priority' => 10,
                'is_active' => true,
            ]);

            // Subject regex: urgent/critical/emergency → Urgent queue
            EmailRoutingRule::create([
                'team_id' => $acmeTeam->id,
                'name' => 'Urgent subject keywords',
                'match_type' => EmailRoutingRule::MATCH_SUBJECT_PATTERN,
                'match_pattern' => '(urgent|critical|emergency)',
                'destination_type' => EmailRoutingRule::DESTINATION_QUEUE,
                'destination_id' => $acmeUrgentQueue->id,
                'priority' => 20,
                'is_active' => true,
            ]);

            // Default catch-all → General queue
            EmailRoutingRule::create([
                'team_id' => $acmeTeam->id,
                'name' => 'Default catch-all',
                'match_type' => EmailRoutingRule::MATCH_DEFAULT,
                'destination_type' => EmailRoutingRule::DESTINATION_QUEUE,
                'destination_id' => $acmeDefaultQueue->id,
                'priority' => 100,
                'is_active' => true,
            ]);
        }

        // Make sure every Extension/SipTrunk/CallQueue we just
        // created has matching ARA rows. The observer fires
        // synchronously inside this process so the rows should
        // already be there, but the resync is idempotent + cheap
        // and makes the seeded baseline self-healing if any model
        // gets touched outside the observer (raw DB inserts, etc).
        $this->command?->call('orbital:resync-realtime');

        $this->command?->info('Demo data seeded:');
        $this->command?->info('  Platform operator:   demo-operator@orbital.test / password');
        $this->command?->info('  Platform supervisor: demo-supervisor@orbital.test / password');
        $this->command?->info('  Client contact:      contact@democustomer.test / password');
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
