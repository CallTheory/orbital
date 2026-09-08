<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Models\CallSessionState;
use App\Models\IntakeGoal;
use App\Models\Message;
use App\Models\Team;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messages\SessionMessageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A caller hangs up part-way through the intake script. Whether what was
 * collected survives is a per-client decision, and getting it wrong in
 * either direction is a real cost: discard when they wanted it kept and
 * the caller is simply lost; keep when they didn't and their inbox fills
 * with fragments nobody chases.
 */
class PartialMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_capture_is_written_mid_call_without_waiting_for_hangup(): void
    {
        $team = $this->client(keepPartials: false);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'reason' => 'Burst pipe in the kitchen']);

        app(SessionMessageWriter::class)->write($state);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertFalse($message->is_partial);
        $this->assertSame('Dana Reed', $message->caller_name);
        $this->assertSame('Burst pipe in the kitchen', $message->reason);
    }

    public function test_incomplete_capture_is_not_written_while_the_call_is_still_up(): void
    {
        // The caller may still be about to say why they rang. Writing now
        // would produce a partial that the complete message then has to
        // reconcile with.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'caller_phone' => '+15551234567']);

        app(SessionMessageWriter::class)->write($state);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_partial_is_discarded_on_hangup_when_the_client_does_not_keep_them(): void
    {
        $team = $this->client(keepPartials: false);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'caller_phone' => '+15551234567']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        app(SessionMessageWriter::class)->write($state);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_partial_is_kept_on_hangup_when_the_client_asks_for_it(): void
    {
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'caller_phone' => '+15551234567']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        app(SessionMessageWriter::class)->write($state);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertTrue($message->is_partial);
        $this->assertSame('Dana Reed', $message->caller_name);
        $this->assertSame('+15551234567', $message->caller_phone);
        $this->assertSame(PartialMessagePolicy::REASON_CALLER_HUNG_UP, $message->partial_reason);
        $this->assertSame(['reason'], $message->missing_fields);
        // The client reads this. A blank reason would look like operator
        // error; this says what actually happened.
        $this->assertStringContainsString('hung up', $message->reason);
    }

    public function test_a_caller_who_said_nothing_useful_is_not_kept_even_under_an_opt_in_policy(): void
    {
        // No callback number and no reason. There is nothing here anyone
        // can act on, and the call log already records that a call came in.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        app(SessionMessageWriter::class)->write($state);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_a_callback_number_alone_is_enough_to_keep(): void
    {
        // Someone can ring this number back. That's the whole bar.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_phone' => '+15551234567']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        app(SessionMessageWriter::class)->write($state);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertTrue($message->is_partial);
        $this->assertSame('Unknown caller', $message->caller_name);
    }

    public function test_a_completed_message_is_never_downgraded_by_a_later_hangup_event(): void
    {
        // The complete message is written mid-call; the room_finished
        // webhook then arrives and runs the writer again. It must not
        // rewrite a finished message as a fragment.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, [
            'caller_name' => 'Dana Reed',
            'caller_phone' => '+15551234567',
            'reason' => 'Burst pipe in the kitchen',
        ]);

        $writer = app(SessionMessageWriter::class);
        $writer->write($state);

        $state->markEnded(PartialMessagePolicy::REASON_SESSION_ABANDONED);
        $writer->write($state->fresh());

        $this->assertSame(1, Message::withoutGlobalScopes()->count());
        $this->assertFalse(Message::withoutGlobalScopes()->first()->is_partial);
    }

    public function test_writing_twice_updates_rather_than_duplicating(): void
    {
        // The worker retries, and the LiveKit webhook races it. Both
        // paths must converge on one message.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'caller_phone' => '+15551234567']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        $writer = app(SessionMessageWriter::class);
        $writer->write($state);
        $writer->write($state->fresh());

        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    public function test_a_partial_is_upgraded_when_the_missing_field_finally_arrives(): void
    {
        // Agent takes a name and number, caller starts to explain, worker
        // reports the reason after the partial was already stored.
        $team = $this->client(keepPartials: true);
        $state = $this->callSession($team, ['caller_name' => 'Dana Reed', 'caller_phone' => '+15551234567']);
        $state->markEnded(PartialMessagePolicy::REASON_CALLER_HUNG_UP);

        $writer = app(SessionMessageWriter::class);
        $writer->write($state);

        $state->captureField('reason', 'Burst pipe in the kitchen');
        $writer->write($state->fresh());

        $this->assertSame(1, Message::withoutGlobalScopes()->count());

        $message = Message::withoutGlobalScopes()->first();
        $this->assertFalse($message->is_partial);
        $this->assertSame('Burst pipe in the kitchen', $message->reason);
        $this->assertNull($message->partial_reason);
    }

    public function test_field_name_variants_from_the_llm_are_normalised(): void
    {
        // The model was told `caller_name`/`reason` and used `name`/`details`.
        $team = $this->client(keepPartials: false);
        $state = $this->callSession($team, ['name' => 'Dana Reed', 'details' => 'Burst pipe']);

        app(SessionMessageWriter::class)->write($state);

        $message = Message::withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertSame('Dana Reed', $message->caller_name);
        $this->assertSame('Burst pipe', $message->reason);
    }

    public function test_goal_level_override_beats_the_client_default(): void
    {
        $policy = app(PartialMessagePolicy::class);
        $keepingClient = $this->client(keepPartials: true);
        $discardingClient = $this->client(keepPartials: false);

        $inherit = new IntakeGoal(['keep_partial_messages' => null]);
        $optOut = new IntakeGoal(['keep_partial_messages' => false]);
        $optIn = new IntakeGoal(['keep_partial_messages' => true]);

        // Inherit is not the same as "off" — this is the distinction the
        // nullable column exists to preserve.
        $this->assertTrue($policy->keepsPartials($keepingClient, $inherit));
        $this->assertFalse($policy->keepsPartials($keepingClient, $optOut));
        $this->assertTrue($policy->keepsPartials($discardingClient, $optIn));
        $this->assertFalse($policy->keepsPartials($discardingClient, $inherit));
    }

    public function test_no_client_means_no_partials(): void
    {
        $this->assertFalse(app(PartialMessagePolicy::class)->keepsPartials(null));
    }

    private function client(bool $keepPartials): Team
    {
        return Team::factory()->create([
            'personal_team' => false,
            'keep_partial_messages' => $keepPartials,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function callSession(Team $team, array $fields): CallSessionState
    {
        return CallSessionState::create([
            'session_key' => 'room-'.$team->id.'-'.count($fields).'-'.md5(serialize($fields)),
            'team_id' => $team->id,
            'fields' => $fields,
        ]);
    }
}
