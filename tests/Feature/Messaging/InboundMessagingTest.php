<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Jobs\ProcessInboundMessageJob;
use App\Models\MessageEntry;
use App\Models\MessageQueue;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The inbound messaging pipeline: webhook → verify → route → thread →
 * persist.
 *
 * The webhook is public — a carrier has to be able to reach it — which
 * makes it the most exposed surface in the channel. The verification
 * tests here are the important ones: failing open would let anyone on
 * the internet put words in a real caller's mouth, inside a real
 * conversation, that a real operator would then act on.
 */
class InboundMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('messaging.providers.log.secret', 'test-secret');
    }

    // ── Webhook authentication ──────────────────────────────────────

    public function test_webhook_rejects_a_request_with_no_credentials(): void
    {
        Queue::fake();

        $this->postJson('/api/messaging/inbound/log', [
            'from' => '+15551110000',
            'to' => '+15559990000',
            'body' => 'hello',
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_webhook_rejects_a_wrong_secret(): void
    {
        Queue::fake();

        $this->withToken('not-the-secret')
            ->postJson('/api/messaging/inbound/log', [
                'from' => '+15551110000',
                'to' => '+15559990000',
                'body' => 'hello',
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_webhook_fails_closed_when_no_secret_is_configured(): void
    {
        // An unconfigured dev provider must reject everything rather
        // than accept everything. This is the failure mode that gets a
        // staging box with a real DNS name filled with garbage.
        config()->set('messaging.providers.log.secret', null);

        Queue::fake();

        $this->withToken('anything')
            ->postJson('/api/messaging/inbound/log', ['from' => '+1', 'to' => '+2', 'body' => 'x'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_unknown_provider_is_a_404_and_not_a_hint(): void
    {
        $this->postJson('/api/messaging/inbound/definitely-not-a-provider', [])
            ->assertNotFound();
    }

    public function test_a_verified_webhook_queues_the_message(): void
    {
        Queue::fake();

        $this->withToken('test-secret')
            ->postJson('/api/messaging/inbound/log', [
                'from' => '+15551110000',
                'to' => '+15559990000',
                'body' => 'hello',
            ])
            ->assertOk()
            ->assertJsonPath('accepted', 1);

        Queue::assertPushed(ProcessInboundMessageJob::class);
    }

    public function test_an_empty_message_is_accepted_but_not_queued(): void
    {
        // Providers send content-free callbacks. Returning non-2xx would
        // make the carrier retry forever.
        Queue::fake();

        $this->withToken('test-secret')
            ->postJson('/api/messaging/inbound/log', [
                'from' => '+15551110000',
                'to' => '+15559990000',
                'body' => '',
            ])
            ->assertOk()
            ->assertJsonPath('accepted', 0);

        Queue::assertNothingPushed();
    }

    // ── Routing and threading ───────────────────────────────────────

    public function test_a_message_is_routed_to_the_client_that_owns_the_endpoint(): void
    {
        $team = $this->client();
        $endpoint = $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '+15559990000', 'My sink is leaking');

        $thread = MessageThread::withoutGlobalScopes()->first();

        $this->assertNotNull($thread);
        $this->assertSame($team->id, $thread->team_id);
        $this->assertSame($endpoint->id, $thread->messaging_endpoint_id);
        $this->assertSame('+15551110000', $thread->remote_address);
        $this->assertSame(MessageThread::STATUS_NEW, $thread->status);

        $entry = MessageEntry::withoutGlobalScopes()->first();
        $this->assertSame('My sink is leaking', $entry->body);
        $this->assertSame(MessageEntry::DIRECTION_INBOUND, $entry->direction);
    }

    public function test_a_message_to_an_unknown_number_is_dropped(): void
    {
        // No client owns this number. Storing it would mean holding an
        // internet stranger's content with nobody to own it.
        $this->deliver('+15551110000', '+15550000000', 'wrong number');

        $this->assertSame(0, MessageThread::withoutGlobalScopes()->count());
        $this->assertSame(0, MessageEntry::withoutGlobalScopes()->count());
    }

    public function test_endpoint_matching_tolerates_carrier_formatting_differences(): void
    {
        // Same Twilio account will hand back +1555… on one webhook and
        // 1555… on the next. Losing a client's message to a plus sign
        // is not acceptable.
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '15559990000', 'no plus sign');
        $this->deliver('+15551110000', '5559990000', 'no country code');

        $this->assertSame(1, MessageThread::withoutGlobalScopes()->count());
        $this->assertSame(2, MessageEntry::withoutGlobalScopes()->count());
    }

    public function test_a_second_message_continues_the_same_thread(): void
    {
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '+15559990000', 'first');
        $this->deliver('+15551110000', '+15559990000', 'second');

        $this->assertSame(1, MessageThread::withoutGlobalScopes()->count());
        $this->assertSame(2, MessageEntry::withoutGlobalScopes()->count());
    }

    public function test_a_different_sender_gets_its_own_thread(): void
    {
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '+15559990000', 'from alice');
        $this->deliver('+15552220000', '+15559990000', 'from bob');

        $this->assertSame(2, MessageThread::withoutGlobalScopes()->count());
    }

    public function test_a_recently_closed_thread_reopens_rather_than_starting_fresh(): void
    {
        // "Sorry, one more thing" the next morning belongs to the same
        // conversation.
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '+15559990000', 'first');

        $thread = MessageThread::withoutGlobalScopes()->first();
        $thread->close();

        $this->deliver('+15551110000', '+15559990000', 'one more thing');

        $this->assertSame(1, MessageThread::withoutGlobalScopes()->count());

        $reopened = MessageThread::withoutGlobalScopes()->first();
        $this->assertSame(MessageThread::STATUS_NEW, $reopened->status);
        $this->assertNull($reopened->closed_at);
    }

    public function test_a_long_closed_thread_does_not_reopen(): void
    {
        // A text six weeks after a resolved complaint is new business,
        // not a continuation buried under old history.
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $this->deliver('+15551110000', '+15559990000', 'first');

        $thread = MessageThread::withoutGlobalScopes()->first();
        $thread->close();
        $thread->forceFill(['closed_at' => now()->subDays(30)])->save();

        $this->deliver('+15551110000', '+15559990000', 'brand new problem');

        $this->assertSame(2, MessageThread::withoutGlobalScopes()->count());
    }

    public function test_a_redelivered_webhook_does_not_duplicate_the_message(): void
    {
        // Carriers retry. The customer's words must appear once.
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $payload = [
            'from' => '+15551110000',
            'to' => '+15559990000',
            'body' => 'only once please',
            'id' => 'provider-msg-1',
        ];

        $this->withToken('test-secret')->postJson('/api/messaging/inbound/log', $payload)->assertOk();
        $this->withToken('test-secret')->postJson('/api/messaging/inbound/log', $payload)->assertOk();

        $this->assertSame(1, MessageEntry::withoutGlobalScopes()->count());
    }

    // ── Queue assignment ────────────────────────────────────────────

    public function test_a_queue_naming_the_endpoint_wins_over_a_catch_all(): void
    {
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $catchAll = MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'General',
            'strategy' => MessageQueue::STRATEGY_MANUAL,
        ]);

        $specific = MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'Emergency line',
            'strategy' => MessageQueue::STRATEGY_MANUAL,
            'matched_addresses' => ['+15559990000'],
            'matched_protocols' => ['sms'],
        ]);

        $this->deliver('+15551110000', '+15559990000', 'help');

        $thread = MessageThread::withoutGlobalScopes()->first();

        $this->assertSame($specific->id, $thread->message_queue_id);
        $this->assertNotSame($catchAll->id, $thread->message_queue_id);
    }

    public function test_a_client_with_one_queue_gets_its_traffic_there_without_a_match_rule(): void
    {
        // Requiring a rule to express "my only queue" is a trap that
        // ends with messages unrouted while a queue sits empty.
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        $only = MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
            'strategy' => MessageQueue::STRATEGY_MANUAL,
        ]);

        $this->deliver('+15551110000', '+15559990000', 'hello');

        $this->assertSame($only->id, MessageThread::withoutGlobalScopes()->first()->message_queue_id);
    }

    public function test_a_queue_that_excludes_the_protocol_does_not_take_the_message(): void
    {
        $team = $this->client();
        $this->endpoint($team, '+15559990000');

        MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'Pager only',
            'strategy' => MessageQueue::STRATEGY_MANUAL,
            'matched_protocols' => ['paging'],
        ]);

        MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'Texts',
            'strategy' => MessageQueue::STRATEGY_MANUAL,
            'matched_protocols' => ['sms'],
        ]);

        $this->deliver('+15551110000', '+15559990000', 'hello');

        $queue = MessageThread::withoutGlobalScopes()->first()->queue;

        $this->assertSame('Texts', $queue->name);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function client(): Team
    {
        return Team::factory()->create(['personal_team' => false]);
    }

    private function endpoint(Team $team, string $address): MessagingEndpoint
    {
        return MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => $address,
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);
    }

    /**
     * Post a message through the real webhook and let the job run
     * inline (QUEUE_CONNECTION is sync under test), so these exercise
     * the whole path rather than the pieces.
     */
    private function deliver(string $from, string $to, string $body): void
    {
        $this->withToken('test-secret')
            ->postJson('/api/messaging/inbound/log', [
                'from' => $from,
                'to' => $to,
                'body' => $body,
            ])
            ->assertOk();
    }
}
