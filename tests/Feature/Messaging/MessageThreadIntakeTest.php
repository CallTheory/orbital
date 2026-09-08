<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Filament\Operator\Pages\ViewMessageThread;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use App\Models\User;
use App\Services\Messages\PartialMessagePolicy;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Taking an answering-service message out of a text conversation.
 *
 * Until this existed an operator could work a text conversation all day
 * and nothing ever reached the client's portal — the channel could talk
 * to customers but could not produce the one artefact the client
 * actually buys.
 *
 * The partial rules are the interesting half. They are the same
 * PartialMessagePolicy the voice path uses, on purpose: an answering
 * service cannot sensibly keep half-finished messages from a phone call
 * and discard them from a text. The one thing this channel changes is
 * that the callback number is always known, because it is the address
 * the customer texted from.
 */
class MessageThreadIntakeTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    public function test_a_complete_capture_writes_a_message_against_the_conversation(): void
    {
        $team = $this->client(keepPartials: false);
        $thread = $this->claimedThread($team, $operator = $this->operator());

        $this->page($thread)->takeMessage([
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551110000',
            'reason' => 'Wants a callback about the invoice',
            'urgency' => Message::URGENCY_URGENT,
        ]);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertFalse($message->is_partial);
        $this->assertSame('Dana Reed', $message->caller_name);
        $this->assertSame('Wants a callback about the invoice', $message->reason);
        $this->assertSame(Message::URGENCY_URGENT, $message->urgency);
        $this->assertSame($thread->id, $message->message_thread_id);
        $this->assertSame($operator->id, $message->created_by_user_id);
    }

    public function test_an_incomplete_capture_is_discarded_when_the_client_does_not_keep_partials(): void
    {
        $team = $this->client(keepPartials: false);
        $thread = $this->claimedThread($team, $this->operator());

        $this->page($thread)->takeMessage([
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551110000',
            'reason' => null,
        ]);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_an_incomplete_capture_is_kept_and_flagged_when_the_client_wants_partials(): void
    {
        $team = $this->client(keepPartials: true);
        $thread = $this->claimedThread($team, $this->operator());

        $this->page($thread)->takeMessage([
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551110000',
            'reason' => null,
            'urgency' => Message::URGENCY_URGENT,
        ]);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertTrue($message->is_partial);
        $this->assertSame(PartialMessagePolicy::REASON_OPERATOR_SAVED, $message->partial_reason);
        $this->assertContains('reason', $message->missing_fields);

        // The operator's urgency selection beats the policy default.
        $this->assertSame(Message::URGENCY_URGENT, $message->urgency);

        // Not a blank the client has to interpret.
        $this->assertNotEmpty($message->reason);
    }

    /**
     * The bar is "could somebody follow this up". On this channel the
     * callback number is always present, so in practice a partial is
     * almost always worth keeping — which is exactly why the empty case
     * still has to be refused.
     */
    public function test_a_capture_with_nothing_in_it_is_refused_even_when_partials_are_kept(): void
    {
        $team = $this->client(keepPartials: true);
        $thread = $this->claimedThread($team, $this->operator());

        $this->page($thread)->takeMessage([
            'caller_name' => null,
            'caller_phone' => null,
            'reason' => null,
        ]);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_an_operator_who_has_not_claimed_the_conversation_cannot_take_a_message(): void
    {
        $team = $this->client(keepPartials: true);
        $holder = $this->operator();
        $thread = $this->claimedThread($team, $holder);

        // A different operator, with the thread already claimed.
        $this->actingAs($this->operator());

        $page = new ViewMessageThread;
        $page->thread = $thread;
        $page->takeMessage([
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551110000',
            'reason' => 'Wants a callback',
        ]);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_the_conversation_records_that_a_message_was_taken(): void
    {
        $team = $this->client(keepPartials: false);
        $thread = $this->claimedThread($team, $this->operator());

        $this->page($thread)->takeMessage([
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551110000',
            'reason' => 'Wants a callback',
        ]);

        $this->assertDatabaseHas('conversation_activities', [
            'subject_id' => $thread->id,
            'action' => 'message_taken',
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function page(MessageThread $thread): ViewMessageThread
    {
        $page = new ViewMessageThread;
        $page->thread = $thread;

        return $page;
    }

    private function client(bool $keepPartials): Team
    {
        return Team::factory()->create([
            'personal_team' => false,
            'keep_partial_messages' => $keepPartials,
        ]);
    }

    private function operator(): User
    {
        return $this->makeUserWithTeamlessRole('operator');
    }

    private function claimedThread(Team $team, User $operator): MessageThread
    {
        $this->actingAs($operator);

        $endpoint = MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+15559990000',
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);

        return MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_IN_PROGRESS,
            'assigned_operator_id' => $operator->id,
            'last_message_at' => now(),
        ]);
    }
}
