<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Jobs\FetchMessageMediaJob;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The backfill sweep for MMS attachments we never took a copy of.
 *
 * Anything received before the fetch-and-store path existed, and
 * anything whose fetch failed at the time, is still just a carrier URL
 * on a clock. This command is how those get a second chance, and the
 * property that makes it safe to run repeatedly — it only ever
 * dispatches for items that have a `url` and no `storage_path` — is
 * what these tests pin down.
 */
class MessageMediaBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_fetch_for_media_still_on_the_carrier(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $entry = $this->entryWithMedia([
            ['url' => 'https://api.twilio.com/media/ME1', 'content_type' => 'image/jpeg'],
        ]);

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();

        Bus::assertDispatched(
            FetchMessageMediaJob::class,
            fn (FetchMessageMediaJob $job): bool => $job->entryId === $entry->id,
        );
    }

    public function test_it_skips_media_we_already_hold_a_copy_of(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $this->entryWithMedia([[
            'url' => 'https://api.twilio.com/media/ME1',
            'content_type' => 'image/jpeg',
            'storage_path' => 'message-media/1/1/abc-0.jpg',
            'storage_disk' => 's3',
        ]]);

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();

        Bus::assertNotDispatched(FetchMessageMediaJob::class);
    }

    public function test_an_entry_with_no_attachments_is_never_considered(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $this->entryWithMedia(null);

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();

        Bus::assertNotDispatched(FetchMessageMediaJob::class);
    }

    /**
     * A carrier URL that has already been consumed and deleted has no
     * `url` left to fetch. Queueing it would be a guaranteed no-op.
     */
    public function test_it_skips_an_attachment_the_provider_no_longer_holds(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $this->entryWithMedia([[
            'url' => null,
            'content_type' => 'image/jpeg',
            'provider_deleted' => true,
        ]]);

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();

        Bus::assertNotDispatched(FetchMessageMediaJob::class);
    }

    public function test_a_dry_run_reports_without_dispatching(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $this->entryWithMedia([
            ['url' => 'https://api.twilio.com/media/ME1', 'content_type' => 'image/jpeg'],
        ]);

        $this->artisan('orbital:backfill-message-media', ['--dry-run' => true])
            ->expectsOutputToContain('Entries needing fetch:  1')
            ->assertSuccessful();

        Bus::assertNotDispatched(FetchMessageMediaJob::class);
    }

    public function test_since_excludes_entries_older_than_the_window(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $old = $this->entryWithMedia(
            [['url' => 'https://api.twilio.com/media/OLD', 'content_type' => 'image/jpeg']],
            occurredAt: now()->subDays(30),
        );
        $recent = $this->entryWithMedia(
            [['url' => 'https://api.twilio.com/media/NEW', 'content_type' => 'image/jpeg']],
            occurredAt: now()->subDay(),
        );

        $this->artisan('orbital:backfill-message-media', [
            '--since' => now()->subDays(7)->toDateString(),
        ])->assertSuccessful();

        Bus::assertDispatched(
            FetchMessageMediaJob::class,
            fn (FetchMessageMediaJob $job): bool => $job->entryId === $recent->id,
        );
        Bus::assertNotDispatched(
            FetchMessageMediaJob::class,
            fn (FetchMessageMediaJob $job): bool => $job->entryId === $old->id,
        );
    }

    public function test_limit_caps_how_much_is_queued_in_one_pass(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        foreach (range(1, 3) as $i) {
            $this->entryWithMedia([
                ['url' => "https://api.twilio.com/media/ME{$i}", 'content_type' => 'image/jpeg'],
            ]);
        }

        $this->artisan('orbital:backfill-message-media', ['--limit' => 2])->assertSuccessful();

        Bus::assertDispatchedTimes(FetchMessageMediaJob::class, 2);
    }

    /**
     * The whole point of the guard: once the fetch has run, a second
     * sweep finds nothing to do. Without this, every re-run would
     * re-download the entire archive from the carrier.
     */
    public function test_a_second_pass_after_a_successful_fetch_queues_nothing(): void
    {
        Bus::fake([FetchMessageMediaJob::class]);

        $entry = $this->entryWithMedia([
            ['url' => 'https://api.twilio.com/media/ME1', 'content_type' => 'image/jpeg'],
        ]);

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();
        Bus::assertDispatchedTimes(FetchMessageMediaJob::class, 1);

        // Stand in for the job having run.
        $entry->forceFill(['media' => [[
            'url' => 'https://api.twilio.com/media/ME1',
            'content_type' => 'image/jpeg',
            'storage_path' => 'message-media/1/1/abc-0.jpg',
            'storage_disk' => 's3',
        ]]])->save();

        $this->artisan('orbital:backfill-message-media')->assertSuccessful();

        Bus::assertDispatchedTimes(FetchMessageMediaJob::class, 1);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>|null  $media
     */
    private function entryWithMedia(?array $media, ?Carbon $occurredAt = null): MessageEntry
    {
        $team = Team::factory()->create(['personal_team' => false]);

        $endpoint = MessagingEndpoint::create([
            'team_id' => $team->id,
            'address' => '+1555999'.str_pad((string) $team->id, 4, '0', STR_PAD_LEFT),
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);

        $thread = MessageThread::create([
            'team_id' => $team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'mms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        return MessageEntry::create([
            'message_thread_id' => $thread->id,
            'team_id' => $team->id,
            'direction' => MessageEntry::DIRECTION_INBOUND,
            'from_address' => '+15551110000',
            'to_address' => $endpoint->address,
            'body' => 'Here is the damage',
            'media' => $media,
            'provider' => 'log',
            'delivery_status' => MessageEntry::STATUS_RECEIVED,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
