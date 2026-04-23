<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Locks in the per-client dialplan layout introduced in Phase 2.
 *
 * The contract this suite enforces:
 *   - `generateDialplanForTenant()` emits a `[tenant_{id}]` context
 *     containing only that client's extensions and queues, and uses
 *     the prefixed Asterisk-side queue name in `Queue()` calls.
 *   - `generateFromTrunkDispatcher()` emits the global inbound
 *     dispatcher with `Goto`s into the right client context per
 *     routing rule.
 *   - `generateDialplanIndex()` lists every non-personal team's
 *     dialplan file as an `#include` directive.
 *   - `writeDialplanForTenant()` produces files at the expected
 *     paths under the configured config_path.
 *   - Two clients with the same queue name don't collide in the
 *     generated output (the prefix from Phase 1 is doing its job
 *     end-to-end).
 */
class PerTenantDialplanTest extends TestCase
{
    use RefreshDatabase;

    protected string $tmpConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The job + service write to the on-disk config path; in
        // tests we redirect to a temp dir so we can introspect the
        // results without touching the real Asterisk volume.
        $this->tmpConfigPath = sys_get_temp_dir().'/orbital-asterisk-test-'.uniqid();
        File::ensureDirectoryExists($this->tmpConfigPath);
        config()->set('telephony.asterisk.config_path', $this->tmpConfigPath);

        // The model observer for CallQueue / Extension / RoutingRule
        // dispatches RegenerateTelephonyConfig synchronously on
        // create — we'd rather control when the job runs in each test.
        Bus::fake([RegenerateTelephonyConfig::class]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpConfigPath)) {
            File::deleteDirectory($this->tmpConfigPath);
        }
        parent::tearDown();
    }

    public function test_per_tenant_generator_emits_namespaced_context(): void
    {
        $team = $this->makeTenant();
        Extension::create([
            'team_id' => $team->id,
            'number' => '201',
            'type' => 'sip_phone',
            'context' => 'internal',
            'is_active' => true,
        ]);
        CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
            'strategy' => 'ringall',
            'timeout' => 30,
        ]);

        $output = app(AsteriskConfigService::class)->generateDialplanForTenant($team->id);

        $this->assertStringContainsString("[tenant_{$team->id}]", $output);
        $this->assertStringContainsString('exten => 201', $output);
        $this->assertStringContainsString("Queue(t{$team->id}_support", $output);
    }

    public function test_two_tenants_with_the_same_queue_name_do_not_collide(): void
    {
        $tenantA = $this->makeTenant('Acme');
        $tenantB = $this->makeTenant('Beta');

        CallQueue::create(['team_id' => $tenantA->id, 'name' => 'Support', 'strategy' => 'ringall', 'timeout' => 30]);
        CallQueue::create(['team_id' => $tenantB->id, 'name' => 'Support', 'strategy' => 'ringall', 'timeout' => 30]);

        $svc = app(AsteriskConfigService::class);
        $a = $svc->generateDialplanForTenant($tenantA->id);
        $b = $svc->generateDialplanForTenant($tenantB->id);

        $this->assertStringContainsString("Queue(t{$tenantA->id}_support", $a);
        $this->assertStringContainsString("Queue(t{$tenantB->id}_support", $b);
        $this->assertStringNotContainsString("Queue(t{$tenantB->id}_support", $a);
        $this->assertStringNotContainsString("Queue(t{$tenantA->id}_support", $b);
    }

    public function test_from_trunk_dispatcher_goto_targets_per_tenant_context(): void
    {
        $team = $this->makeTenant();
        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'Provider',
            'host' => 'sip.provider.test',
            'port' => 5060,
            'transport' => 'udp',
            'is_active' => true,
        ]);
        // Direct DB insert because RoutingRule schema requires
        // a few columns the factory doesn't set; we just need
        // a valid row for the dispatcher render.
        RoutingRule::create([
            'team_id' => $team->id,
            'sip_trunk_id' => $trunk->id,
            'name' => 'Demo DID',
            'match_type' => 'did',
            'match_pattern' => '+15550100',
            'destination_type' => 'extension',
            'destination_id' => 201,
            'priority' => 10,
            'is_active' => true,
        ]);

        $output = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();

        $this->assertStringContainsString('[from-trunk]', $output);
        $this->assertStringContainsString("Goto(tenant_{$team->id},201,1)", $output);
        $this->assertStringContainsString('+15550100', $output);
    }

    public function test_dialplan_index_includes_every_non_personal_team(): void
    {
        $a = $this->makeTenant('Acme');
        $b = $this->makeTenant('Beta');

        $output = app(AsteriskConfigService::class)->generateDialplanIndex();

        $this->assertStringContainsString('#include "from-trunk.conf"', $output);
        $this->assertStringContainsString("#include \"clients/{$a->id}-dialplan.conf\"", $output);
        $this->assertStringContainsString("#include \"clients/{$b->id}-dialplan.conf\"", $output);
    }

    public function test_write_dialplan_for_tenant_produces_expected_files(): void
    {
        $team = $this->makeTenant();
        Extension::create([
            'team_id' => $team->id,
            'number' => '301',
            'type' => 'sip_phone',
            'context' => 'internal',
            'is_active' => true,
        ]);

        app(AsteriskConfigService::class)->writeDialplanForTenant($team->id);

        $tenantFile = $this->tmpConfigPath."/clients/{$team->id}-dialplan.conf";
        $dispatcherFile = $this->tmpConfigPath.'/from-trunk.conf';

        $this->assertFileExists($tenantFile);
        $this->assertFileExists($dispatcherFile);
        $this->assertStringContainsString("[tenant_{$team->id}]", file_get_contents($tenantFile));
    }

    public function test_delete_dialplan_for_tenant_removes_the_file(): void
    {
        $team = $this->makeTenant();
        $svc = app(AsteriskConfigService::class);

        $svc->writeDialplanForTenant($team->id);
        $tenantFile = $this->tmpConfigPath."/clients/{$team->id}-dialplan.conf";
        $this->assertFileExists($tenantFile);

        $svc->deleteDialplanForTenant($team->id);
        $this->assertFileDoesNotExist($tenantFile);
    }

    public function test_scoped_job_only_writes_one_tenant_file(): void
    {
        Bus::fake();

        $teamA = $this->makeTenant('Acme');
        $teamB = $this->makeTenant('Beta');

        Extension::create(['team_id' => $teamA->id, 'number' => '201', 'type' => 'sip_phone', 'context' => 'internal', 'is_active' => true]);
        Extension::create(['team_id' => $teamB->id, 'number' => '301', 'type' => 'sip_phone', 'context' => 'internal', 'is_active' => true]);

        // Run the job synchronously for client A only.
        $job = new RegenerateTelephonyConfig($teamA->id);
        $svc = $this->mock(AsteriskConfigService::class);
        $svc->shouldReceive('writeDialplanForTenant')->once()->with($teamA->id);
        $svc->shouldReceive('reloadDialplan')->once()->andReturnTrue();
        $svc->shouldNotReceive('writeAllDialplans');
        $svc->shouldNotReceive('reloadAsterisk');

        $job->handle($svc);
    }

    protected function makeTenant(string $name = 'Acme'): Team
    {
        $owner = User::factory()->create();
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
        ]);
    }
}
