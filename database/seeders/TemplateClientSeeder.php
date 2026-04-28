<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\ClientDid;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeGoal;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Services\Flows\ChannelTriggerSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds three "template" tenants that demonstrate the common
 * answering-service patterns. These run alongside DemoClientSeeder
 * — the demo is the real sandbox; these are showroom examples a
 * platform operator can clone when onboarding a new client.
 *
 * Each template is a real, working tenant — routing rules, queues,
 * personas all set up — so `orbital:generate-config` produces a
 * complete dialplan for it.
 *
 * Templates:
 *   1. Voicemail Only          — DID → Asterisk VoiceMail()
 *   2. Live Operators          — DID → queue of human operators
 *   3. Live + AI Overflow      — DID → queue; AI picks up when empty
 *      (mirrors the Demo Customer shape, bundled as a template for
 *       easy cloning)
 *
 * Local-only seeder. Idempotent — skips tenants that already exist.
 */
class TemplateClientSeeder extends Seeder
{
    public function run(): void
    {
        // Platform operator group — reuse the one DemoClientSeeder
        // created so every tenant shares the same staff pool.
        $operatorGroup = AgentGroup::where('name', 'all-operators')->first();
        if (! $operatorGroup) {
            $this->command?->warn('TemplateClientSeeder: all-operators group missing — run DemoClientSeeder first.');

            return;
        }

        $sharedTrunk = SipTrunk::where('name', 'Platform Shared Trunk')->first();

        $this->seedVoicemailOnly($sharedTrunk);
        $this->seedLiveOperatorsOnly($operatorGroup, $sharedTrunk);
        $this->seedLiveWithAiOverflow($operatorGroup, $sharedTrunk);

        // Re-sync ARA so queue_members / PJSIP rows land for the
        // queues + extensions we just created.
        $this->command?->call('orbital:resync-realtime');
    }

    /**
     * Voicemail Only — the simplest template. Inbound call answers,
     * plays the mailbox's greeting, records a message. No human, no
     * AI. `destination_id` on the RoutingRule is the mailbox number
     * — admins still need to add a matching entry to voicemail.conf
     * (or run the future voicemail.conf generator) for the recorder
     * to accept the box.
     */
    protected function seedVoicemailOnly(?SipTrunk $trunk): void
    {
        if (Team::where('name', 'Template: Voicemail Only')->exists()) {
            return;
        }

        $team = $this->makeTeam('Template: Voicemail Only', 900001, 'contact@tpl-voicemail.test');
        $mailboxNumber = (string) $team->account_number;

        if ($trunk) {
            ClientDid::create([
                'team_id' => $team->id,
                'sip_trunk_id' => $trunk->id,
                'number' => '+15550000001',
                'label' => 'Main',
                'is_active' => true,
            ]);
        }

        RoutingRule::create([
            'team_id' => $team->id,
            'name' => 'Voicemail on inbound 15550000001',
            'sip_trunk_id' => null,
            'match_type' => 'did',
            // Match ONLY this tenant's DID. Using _X. would collide
            // with other tenants' catch-all rules in the generated
            // from-trunk dispatcher — Asterisk picks only the first
            // extension priority, so multiple _X. rules shadow each
            // other. Specific DIDs disambiguate cleanly.
            'match_pattern' => '15550000001',
            'destination_type' => 'voicemail',
            'destination_id' => $mailboxNumber,
            'priority' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Live Operators Only — humans answer every call. No AI persona,
     * no overflow target. If everyone's busy or offline, the queue
     * times out and the caller hangs up.
     */
    protected function seedLiveOperatorsOnly(AgentGroup $operatorGroup, ?SipTrunk $trunk): void
    {
        if (Team::where('name', 'Template: Live Operators')->exists()) {
            return;
        }

        $team = $this->makeTeam('Template: Live Operators', 900002, 'contact@tpl-live.test');

        if ($trunk) {
            ClientDid::create([
                'team_id' => $team->id,
                'sip_trunk_id' => $trunk->id,
                'number' => '+15550000002',
                'label' => 'Main',
                'is_active' => true,
            ]);
        }

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'tpl-live-main',
            'music_on_hold' => 'default',
            // The key differentiator from the AI-overflow template:
            // no overflow_agent_persona_id. Human or nothing.
            'overflow_agent_persona_id' => null,
            'agent_group_id' => $operatorGroup->id,
        ]);

        RoutingRule::create([
            'team_id' => $team->id,
            'name' => 'Inbound 15550000002 → operator queue',
            'sip_trunk_id' => null,
            'match_type' => 'did',
            'match_pattern' => '15550000002',
            'destination_type' => 'queue',
            'destination_id' => $queue->id,
            'priority' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Live Operators + AI Overflow — humans first, AI picks up when
     * the queue empties out. Identical shape to the Demo tenant;
     * packaged separately so platform operators can clone THIS row
     * when onboarding a new customer instead of duplicating the
     * full demo dataset.
     */
    protected function seedLiveWithAiOverflow(AgentGroup $operatorGroup, ?SipTrunk $trunk): void
    {
        if (Team::where('name', 'Template: Live + AI Overflow')->exists()) {
            return;
        }

        $team = $this->makeTeam('Template: Live + AI Overflow', 900003, 'contact@tpl-hybrid.test');

        if ($trunk) {
            ClientDid::create([
                'team_id' => $team->id,
                'sip_trunk_id' => $trunk->id,
                'number' => '+15550000003',
                'label' => 'Main',
                'is_active' => true,
            ]);
        }

        $persona = AgentPersona::create([
            'team_id' => $team->id,
            'name' => 'Overflow Ava',
            'role' => 'AI overflow agent',
            'description' => 'Takes messages when every human operator is busy.',
            'system_prompt' => implode("\n", [
                'You are an AI answering-service agent picking up calls when all human operators are busy.',
                '',
                'RULES:',
                '- Greet the caller warmly and apologize briefly for the wait.',
                '- Offer to take a message.',
                '- NEVER make up information or claim to forward them to someone.',
                '- Two sentences max per turn.',
            ]),
            'greeting' => 'Thanks for holding — I\'m the AI assistant. Our operators are all busy, may I take a message for you?',
            'outbound_greeting' => 'Hi, this is an AI assistant — do you have a moment?',
            'personality' => 'Warm, efficient, honest about being an AI.',
            'voice_id' => '21m00Tcm4TlvDq8ikWAM',
            'llm_provider' => 'anthropic',
            'llm_model' => 'claude-sonnet-4-20250514',
            'stt_provider' => 'elevenlabs',
            'tts_provider' => 'elevenlabs',
            'is_active' => true,
        ]);

        Extension::create([
            'team_id' => $team->id,
            'number' => '9'.$team->account_number,
            'type' => 'ai_agent',
            'label' => 'Overflow AI',
            'assignable_type' => $persona->getMorphClass(),
            'assignable_id' => $persona->id,
            'context' => 'internal',
            'is_active' => true,
        ]);

        // Simple message-taking flow so the AI has structured intake.
        // Composed from typed primitives: gather name / phone / reason
        // then save.
        $byKey = IntakeGoal::whereIn('key', [
            'gather_text', 'gather_phone', 'save_message',
        ])->get()->keyBy('key');

        $defaultGraph = app(ChannelTriggerSeeder::class)->ensureBootstrap($team);

        $flow = IntakeFlow::create([
            'team_id' => $team->id,
            'orchestration_id' => $defaultGraph->id,
            'name' => 'Overflow Default',
            'is_active' => true,
        ]);

        $textId = $byKey['gather_text']->id;
        $phoneId = $byKey['gather_phone']->id;
        $saveId = $byKey['save_message']->id;

        IntakeFlowStep::create(['flow_id' => $flow->id, 'intake_goal_id' => $textId,  'position' => 0, 'step_params' => ['slot' => 'caller_name',    'label' => 'Caller Name',     'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $flow->id, 'intake_goal_id' => $phoneId, 'position' => 1, 'step_params' => ['slot' => 'callback_phone', 'label' => 'Callback Phone',  'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $flow->id, 'intake_goal_id' => $textId,  'position' => 2, 'step_params' => ['slot' => 'reason',         'label' => 'Reason for Call', 'required' => true]]);
        IntakeFlowStep::create(['flow_id' => $flow->id, 'intake_goal_id' => $saveId,  'position' => 3, 'step_params' => ['include_slots' => ['caller_name', 'callback_phone', 'reason'], 'destination' => 'inbox']]);

        $persona->update(['default_flow_id' => $flow->id]);

        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'tpl-hybrid-main',
            'music_on_hold' => 'default',
            'overflow_agent_persona_id' => $persona->id,
            'agent_group_id' => $operatorGroup->id,
        ]);

        RoutingRule::create([
            'team_id' => $team->id,
            'name' => 'Inbound 15550000003 → queue (AI on overflow)',
            'sip_trunk_id' => null,
            'match_type' => 'did',
            'match_pattern' => '15550000003',
            'destination_type' => 'queue',
            'destination_id' => $queue->id,
            'priority' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Shared tenant-creation helper. Mirrors DemoClientSeeder's
     * provision path: create contact user, create Team, wire the
     * contact into the client_user role via ClientProvisioner.
     */
    protected function makeTeam(string $name, int $accountNumber, string $contactEmail): Team
    {
        $contact = User::firstOrCreate(
            ['email' => $contactEmail],
            [
                'name' => $name.' Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $team = Team::forceCreate([
            'user_id' => $contact->id,
            'name' => $name,
            'account_number' => $accountNumber,
            'personal_team' => false,
            'max_users' => 5,
            'max_concurrent_calls' => 5,
        ]);

        $contact->current_team_id = $team->id;
        $contact->save();

        app(ClientProvisioner::class)->provision($team, $contact);

        return $team;
    }
}
