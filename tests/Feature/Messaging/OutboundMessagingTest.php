<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Filament\Operator\Pages\ViewMessageThread;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\OutboundMessageService;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Replying on a message thread.
 *
 * The load-bearing behaviour here is that a FAILED send is recorded
 * rather than discarded. SMTP either accepts a mail or doesn't; SMS can
 * be accepted and then fail at the handset minutes later. An operator
 * who can't see that their reply never landed will assume the customer
 * was told something they were never told — the worst failure this
 * channel has.
 */
class OutboundMessagingTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    public function test_an_operator_reply_is_recorded_and_moves_the_thread_to_awaiting_reply(): void
    {
        [$thread, $operator] = $this->threadWithOperator();

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread, $operator, 'Someone will call you back within the hour.');

        $this->assertSame(MessageEntry::DIRECTION_OUTBOUND, $entry->direction);
        $this->assertSame($operator->id, $entry->sent_by_user_id);
        $this->assertSame('Someone will call you back within the hour.', $entry->body);
        $this->assertFalse($entry->failedToDeliver());

        $this->assertSame(
            MessageThread::STATUS_AWAITING_REPLY,
            $thread->fresh()->status,
        );
    }

    public function test_a_failed_send_is_still_recorded_on_the_thread(): void
    {
        [$thread, $operator] = $this->threadWithOperator();

        // Point the endpoint at a driver that isn't registered — the
        // same shape as a provider being removed or misconfigured.
        $thread->endpoint->forceFill(['provider' => 'no-such-provider'])->save();

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread->fresh(), $operator, 'This will not go out.');

        $this->assertTrue($entry->failedToDeliver());
        $this->assertNotNull($entry->delivery_error);
        // The operator must be able to see the attempt in the transcript.
        $this->assertSame(1, MessageEntry::withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    public function test_a_failed_send_does_not_park_the_thread_in_awaiting_reply(): void
    {
        // Marking a thread "awaiting reply" after a send that never
        // happened parks a customer who was never contacted in the
        // least-watched status there is.
        [$thread, $operator] = $this->threadWithOperator();
        $thread->endpoint->forceFill(['provider' => 'no-such-provider'])->save();

        app(OutboundMessageService::class)
            ->replyAsOperator($thread->fresh(), $operator, 'This will not go out.');

        $this->assertNotSame(MessageThread::STATUS_AWAITING_REPLY, $thread->fresh()->status);
    }

    public function test_a_deleted_endpoint_produces_a_recorded_failure_not_an_exception(): void
    {
        [$thread, $operator] = $this->threadWithOperator();

        $thread->endpoint->delete();

        $entry = app(OutboundMessageService::class)
            ->replyAsOperator($thread->fresh(), $operator, 'Where did the number go?');

        $this->assertTrue($entry->failedToDeliver());
    }

    public function test_only_the_claiming_operator_can_reply(): void
    {
        [$thread, $operator] = $this->threadWithOperator();
        $other = $this->makeUserWithTeamlessRole('operator');

        $page = new ViewMessageThread;
        $page->thread = $thread->fresh();

        $this->actingAs($other);
        $this->assertFalse($page->canReply());

        $this->actingAs($operator);
        $this->assertTrue($page->canReply());
    }

    public function test_claiming_is_exclusive(): void
    {
        // Two operators clicking Claim within the table's poll interval
        // is an ordinary race. The customer must not end up talking to
        // both.
        [$thread, $first] = $this->threadWithOperator();
        $second = $this->makeUserWithTeamlessRole('operator');

        $this->assertFalse($thread->fresh()->claimFor($second));
        $this->assertSame($first->id, $thread->fresh()->assigned_operator_id);
    }

    /**
     * Renders the two new operator surfaces. Cheap, and it catches the
     * class of mistake — a wrong Filament API, a deleted enum, a typo in
     * a column reference — that otherwise only surfaces when an operator
     * clicks the page mid-shift.
     */
    public function test_the_operator_message_surfaces_render(): void
    {
        [$thread, $operator] = $this->threadWithOperator();

        $this->actingAs($operator)
            ->get('/operator/message-inbox')
            ->assertOk();

        $this->actingAs($operator)
            ->get('/operator/message-thread/'.$thread->id)
            ->assertOk()
            ->assertSee($thread->remote_address);
    }

    public function test_the_client_portal_lists_conversations_but_offers_no_reply(): void
    {
        // The portal is a view of what was handled on the client's
        // behalf, not a second place to answer from. A client texting
        // from the same number mid-conversation would collide with the
        // operator working it.
        $user = $this->makeTenantUser();

        MessageThread::create([
            'team_id' => $user->current_team_id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/portal/texts')
            ->assertOk()
            ->assertSee('+15551110000')
            ->assertDontSee('Send');
    }

    public function test_releasing_returns_the_conversation_to_the_pool(): void
    {
        [$thread] = $this->threadWithOperator();

        $thread->release();

        $this->assertNull($thread->fresh()->assigned_operator_id);
        $this->assertSame(MessageThread::STATUS_NEW, $thread->fresh()->status);
    }

    /**
     * @return array{0: MessageThread, 1: User}
     */
    private function threadWithOperator(): array
    {
        config()->set('messaging.providers.log.secret', 'test-secret');

        $team = Team::factory()->create(['personal_team' => false]);
        $operator = $this->makeUserWithTeamlessRole('operator');

        $endpoint = MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+15559990000',
            'protocol' => 'sms',
            'provider' => 'log',
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

        $thread->claimFor($operator);

        return [$thread->fresh()->load('endpoint'), $operator];
    }
}
