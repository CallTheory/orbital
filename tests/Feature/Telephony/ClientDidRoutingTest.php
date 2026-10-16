<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\ClientDid;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A DID's "Send calls to" setting is the routing rule behind it.
 */
class ClientDidRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
    }

    private function team(): Team
    {
        return Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme', 'personal_team' => false]);
    }

    private function rules(): Collection
    {
        return RoutingRule::withoutGlobalScopes()->withTrashed()->get();
    }

    public function test_routing_a_did_creates_its_rule_and_the_dispatcher_entry(): void
    {
        $team = $this->team();
        $did = ClientDid::create([
            'team_id' => $team->id, 'number' => '+12892271019', 'is_active' => true,
            'destination_type' => 'extension', 'destination_id' => 1001,
        ]);

        $rule = $this->rules()->sole();
        $this->assertSame($did->id, $rule->client_did_id);
        $this->assertSame('+12892271019', $rule->match_pattern);
        $this->assertSame('extension', $rule->destination_type);
        $this->assertSame(1001, (int) $rule->destination_id);
        Bus::assertDispatched(RegenerateTelephonyConfig::class);

        $dispatcher = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();
        $this->assertStringContainsString('exten => +12892271019,1', $dispatcher);
        $this->assertStringContainsString("Goto(tenant_{$team->id},1001,1)", $dispatcher);
    }

    public function test_changing_the_destination_updates_the_same_rule(): void
    {
        $did = ClientDid::create([
            'team_id' => $this->team()->id, 'number' => '+15550001111', 'is_active' => true,
            'destination_type' => 'extension', 'destination_id' => 1001,
        ]);

        $did->update(['destination_type' => 'queue', 'destination_id' => 7]);

        $rule = $this->rules()->sole();
        $this->assertSame('queue', $rule->destination_type);
        $this->assertSame(7, (int) $rule->destination_id);
    }

    public function test_unrouting_deactivating_or_deleting_removes_the_rule_for_good(): void
    {
        $team = $this->team();
        $make = fn (string $n) => ClientDid::create([
            'team_id' => $team->id, 'number' => $n, 'is_active' => true,
            'destination_type' => 'extension', 'destination_id' => 1001,
        ]);

        $make('+15550000001')->update(['destination_type' => null, 'destination_id' => null]);
        $make('+15550000002')->update(['is_active' => false]);
        $make('+15550000003')->delete();

        // Hard-deleted: the dispatcher ignores soft deletes.
        $this->assertCount(0, $this->rules());
        $this->assertStringNotContainsString('+1555000000', app(AsteriskConfigService::class)->generateFromTrunkDispatcher());
    }

    public function test_an_unrouted_did_has_no_rule(): void
    {
        ClientDid::create(['team_id' => $this->team()->id, 'number' => '+15550002222', 'is_active' => true]);

        $this->assertCount(0, $this->rules());
    }
}
