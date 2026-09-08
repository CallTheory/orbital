<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Jobs\ProcessMessageWithAgentJob;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\MessagingOptOut;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\OptOutRegistry;
use App\Services\Messaging\OutboundMessageService;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * The two things that keep a text-messaging channel connected: sending
 * only through a carrier sender pool, and never texting somebody who
 * told us to stop.
 *
 * Neither failure announces itself. A message sent from a bare number
 * delivers fine right up until the carriers de-register it, and a
 * message to an opted-out handset delivers fine right up until the
 * complaint. So these tests are about paths that MUST NOT happen rather
 * than features that must work, and none of them should ever be
 * loosened to make a change pass.
 */
class MessagingComplianceTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);

        config()->set('messaging.providers.log.secret', 'test-secret');
        config()->set('messaging.providers.twilio.account_sid', 'ACtest');
        config()->set('messaging.providers.twilio.auth_token', 'token');
    }

    // ── Sender pool is mandatory ────────────────────────────────────

    public function test_twilio_refuses_to_send_without_a_sender_pool(): void
    {
        Http::fake();

        [$thread] = $this->twilioThread(senderPoolId: null);

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread, $this->operator(), 'Are you still there?');

        // Not merely unsent — never attempted. A bare-From send would
        // have been accepted by Twilio and delivered, which is exactly
        // why the check has to happen before the request goes out.
        Http::assertNothingSent();

        $this->assertTrue($entry->failedToDeliver());
        $this->assertStringContainsString('Messaging Service', (string) $entry->delivery_error);
    }

    public function test_twilio_sends_through_the_pool_and_never_names_a_from_number(): void
    {
        Http::fake([
            '*/Messages.json' => Http::response(['sid' => 'SM123', 'from' => '+15550001111'], 201),
        ]);

        [$thread] = $this->twilioThread(senderPoolId: 'MGpool');

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread, $this->operator(), 'On our way.');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['MessagingServiceSid'] ?? null) === 'MGpool'
                && ! array_key_exists('From', $body);
        });

        $this->assertFalse($entry->failedToDeliver());

        // The pool picks the number, so the pool's answer is what the
        // conversation records — not the endpoint's display address.
        $this->assertSame('+15550001111', $entry->from_address);
    }

    public function test_inbound_routes_on_the_sender_pool_when_the_number_is_not_ours(): void
    {
        $team = $this->client();

        MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => null,
            'protocol' => 'sms',
            'provider' => 'log',
            'sender_pool_id' => 'MGpool',
            'is_active' => true,
        ]);

        // A number the client added to their pool this morning and never
        // told us about. Routing on the pool is what makes it land.
        $this->deliver('+15551110000', '+15557778888', 'hello', ['sender_pool_id' => 'MGpool']);

        $thread = MessageThread::withoutGlobalScopes()->first();

        $this->assertNotNull($thread);
        $this->assertSame($team->id, $thread->team_id);
    }

    // ── STOP / START / HELP ─────────────────────────────────────────

    public function test_stop_records_an_opt_out_and_closes_the_conversation(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        $this->deliver('+15551110000', '+15559990000', 'Can someone call me back?');
        $this->deliver('+15551110000', '+15559990000', 'STOP');

        $thread = MessageThread::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(MessageThread::STATUS_CLOSED, $thread->status);
        $this->assertNull($thread->assigned_operator_id);
        $this->assertTrue(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));
    }

    public function test_the_stop_message_itself_is_kept_in_the_conversation(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        $this->deliver('+15551110000', '+15559990000', 'STOP');

        // Swallowing it would leave a thread that simply goes quiet,
        // with nothing to explain why nobody replied.
        $this->assertSame(1, MessageEntry::withoutGlobalScopes()->where('body', 'STOP')->count());
    }

    public function test_a_sentence_containing_stop_is_not_an_opt_out(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        $this->deliver('+15551110000', '+15559990000', 'Can you stop by the office at four?');

        // Treating this as a revocation cuts off a customer who was
        // making an appointment.
        $this->assertFalse(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));
        $this->assertSame(
            MessageThread::STATUS_NEW,
            MessageThread::withoutGlobalScopes()->firstOrFail()->status,
        );
    }

    public function test_stop_is_matched_case_insensitively_and_through_punctuation(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        $this->deliver('+15551110000', '+15559990000', ' Stop. ');

        $this->assertTrue(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));
    }

    public function test_a_redelivered_stop_is_still_honoured(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        // Carriers retry webhooks, and a previous attempt can die
        // between writing the entry and recording the consent. On the
        // retry the entry already exists, so anything sequenced after
        // the duplicate check would be skipped — which for the STOP
        // itself is a compliance failure, not a cosmetic one.
        $payload = ['id' => 'MSG-DUPLICATE', 'from' => '+15551110000', 'to' => '+15559990000', 'body' => 'STOP'];

        $this->withToken('test-secret')->postJson('/api/messaging/inbound/log', $payload)->assertOk();
        $this->withToken('test-secret')->postJson('/api/messaging/inbound/log', $payload)->assertOk();

        $this->assertTrue(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));

        // And the duplicate must still not show up twice in the
        // conversation the operator reads.
        $this->assertSame(1, MessageEntry::withoutGlobalScopes()->count());
    }

    public function test_start_lifts_the_suppression(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        $this->deliver('+15551110000', '+15559990000', 'STOP');
        $this->assertTrue(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));

        $this->deliver('+15551110000', '+15559990000', 'START');
        $this->assertFalse(app(OptOutRegistry::class)->isSuppressed($team->id, '+15551110000'));

        // The record survives so the history is still answerable.
        $record = MessagingOptOut::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($record->opted_out_at);
        $this->assertNotNull($record->opted_in_at);
    }

    public function test_a_repeat_stop_does_not_move_the_date_they_first_asked(): void
    {
        $team = $this->client();
        $registry = app(OptOutRegistry::class);

        $first = $registry->optOut($team->id, '+15551110000', 'stop');
        $originalDate = $first->opted_out_at;

        $this->travel(2)->days();

        $second = $registry->optOut($team->id, '+15551110000', 'stop');

        // The date a complaint gets measured against is when they FIRST
        // told us, not the last time they repeated themselves.
        $this->assertTrue($originalDate->equalTo($second->opted_out_at));
        $this->assertSame(1, MessagingOptOut::withoutGlobalScopes()->count());
    }

    public function test_an_opt_out_matches_the_same_handset_spelled_differently(): void
    {
        $team = $this->client();

        app(OptOutRegistry::class)->optOut($team->id, '+15551110000', 'stop');

        // Carriers send all of these for one phone. Honouring a STOP
        // only when the spelling matches is the same as not honouring
        // it at all.
        foreach (['+15551110000', '15551110000', '5551110000', '+1 (555) 111-0000'] as $spelling) {
            $this->assertTrue(
                app(OptOutRegistry::class)->isSuppressed($team->id, $spelling),
                "Suppression missed for spelling [{$spelling}]",
            );
        }
    }

    public function test_an_opt_out_covers_the_whole_client_not_just_the_number_texted(): void
    {
        $team = $this->client();
        $this->endpoint($team);

        // A second line for the same client.
        $other = MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+15552223333',
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);

        $this->deliver('+15551110000', '+15559990000', 'STOP');

        $thread = MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $other->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread->load('endpoint'), $this->operator(), 'Just checking in.');

        $this->assertTrue($entry->failedToDeliver());
    }

    public function test_an_opt_out_does_not_leak_between_clients(): void
    {
        $suppressed = $this->client();
        $other = $this->client();

        app(OptOutRegistry::class)->optOut($suppressed->id, '+15551110000', 'stop');

        // One business being told to stop says nothing about another.
        $this->assertFalse(app(OptOutRegistry::class)->isSuppressed($other->id, '+15551110000'));
    }

    public function test_an_operator_reply_to_a_suppressed_thread_is_blocked_and_recorded(): void
    {
        $team = $this->client();
        $endpoint = $this->endpoint($team);
        $operator = $this->makeUserWithTeamlessRole('operator');

        $thread = MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        app(OptOutRegistry::class)->optOut($team->id, '+15551110000', 'stop');

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread->load('endpoint'), $operator, 'Sorry about that!');

        $this->assertTrue($entry->failedToDeliver());
        $this->assertStringContainsString('opted out', (string) $entry->delivery_error);

        // Recorded, not thrown away — the operator has to be able to
        // see that the customer never got this.
        $this->assertSame(1, MessageEntry::withoutGlobalScopes()->count());

        // And the thread must not advance to "awaiting reply", which
        // would park a customer nobody actually contacted in the
        // least-watched status there is.
        $this->assertSame(MessageThread::STATUS_NEW, $thread->fresh()->status);
    }

    public function test_the_ai_stands_down_before_spending_a_token_on_a_suppressed_thread(): void
    {
        Http::fake();

        $team = $this->client();
        $endpoint = $this->endpoint($team);

        $thread = MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        app(OptOutRegistry::class)->optOut($team->id, '+15551110000', 'stop');

        app()->call([new ProcessMessageWithAgentJob($thread->id), 'handle']);

        Http::assertNothingSent();
    }

    public function test_stop_and_help_never_reach_the_ai(): void
    {
        config()->set('messaging.auto_reply_enabled', true);

        // Partial fake: the inbound job still runs for real, only the
        // AI handoff is intercepted.
        Bus::fake([ProcessMessageWithAgentJob::class]);

        $team = $this->client();
        $this->endpoint($team);

        // Positive control first — without this the assertions below
        // would also pass if the AI path were simply broken.
        $this->deliver('+15553330000', '+15559990000', 'Is anyone there?');
        Bus::assertDispatched(ProcessMessageWithAgentJob::class);

        // The carrier's sender pool already answers both of these with
        // the registered campaign text. A second, generated reply is at
        // best redundant and at worst contradicts the mandated "reply
        // STOP to opt out" line it just sent.
        Bus::assertDispatchedTimes(ProcessMessageWithAgentJob::class, 1);

        $this->deliver('+15551110000', '+15559990000', 'HELP');
        $this->deliver('+15552220000', '+15559990000', 'STOP');

        Bus::assertDispatchedTimes(ProcessMessageWithAgentJob::class, 1);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function client(): Team
    {
        return Team::factory()->create(['personal_team' => false]);
    }

    private function operator(): User
    {
        return $this->makeUserWithTeamlessRole('operator');
    }

    private function endpoint(Team $team): MessagingEndpoint
    {
        return MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+15559990000',
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: MessageThread, 1: MessagingEndpoint}
     */
    private function twilioThread(?string $senderPoolId): array
    {
        $team = $this->client();

        $endpoint = MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+15559990000',
            'protocol' => 'sms',
            'provider' => 'twilio',
            'sender_pool_id' => $senderPoolId,
            'is_active' => true,
        ]);

        $thread = MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        return [$thread->load('endpoint'), $endpoint];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function deliver(string $from, string $to, string $body, array $extra = []): void
    {
        $this->withToken('test-secret')
            ->postJson('/api/messaging/inbound/log', array_merge([
                'from' => $from,
                'to' => $to,
                'body' => $body,
            ], $extra))
            ->assertOk();
    }
}
