<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Jobs\FetchMessageMediaJob;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use App\Services\Messaging\Providers\TwilioProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MMS attachments, from the carrier's URL to our own object store.
 *
 * The reason this exists: a provider URL is not a copy of anything. It
 * expires on the carrier's schedule, it needs the carrier's credentials
 * to read, and it disappears entirely when the account closes or the
 * client changes vendor. A client opening a six-month-old conversation
 * to find the photograph of the damage gone has lost the part of the
 * message that mattered — and unlike a lost text, there is nothing to
 * re-read.
 */
class MessageMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config()->set('messaging.media.disk', 's3');
    }

    public function test_media_is_pulled_off_the_carrier_and_stored(): void
    {
        $file = $this->localMedia('photo.png', $this->pngBytes());
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => null]]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $media = $entry->fresh()->media;

        $this->assertArrayHasKey('storage_path', $media[0]);
        Storage::disk('s3')->assertExists($media[0]['storage_path']);
        $this->assertSame($this->pngBytes(), Storage::disk('s3')->get($media[0]['storage_path']));
        $this->assertSame(strlen($this->pngBytes()), $media[0]['size']);
    }

    public function test_stored_media_is_served_from_our_own_copy(): void
    {
        $file = $this->localMedia('photo.png', $this->pngBytes());
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => null]]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $item = $entry->fresh()->mediaItems()[0];

        $this->assertTrue($item['stored']);
        $this->assertNotNull($item['url']);
        $this->assertStringNotContainsString('file://', $item['url']);
    }

    public function test_an_unfetchable_attachment_leaves_the_provider_url_alone(): void
    {
        $entry = $this->entryWithMedia([
            ['url' => 'file:///nowhere/at/all.jpg', 'content_type' => 'image/jpeg'],
        ]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $media = $entry->fresh()->media;

        // Degraded, not lost. The operator can still open the carrier's
        // link while whatever broke gets sorted out.
        $this->assertArrayNotHasKey('storage_path', $media[0]);
        $this->assertSame('file:///nowhere/at/all.jpg', $media[0]['url']);
    }

    public function test_an_oversized_attachment_is_left_with_the_provider(): void
    {
        config()->set('messaging.media.max_bytes', 8);

        $file = $this->localMedia('big.bin', str_repeat('A', 64));
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => null]]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $this->assertArrayNotHasKey('storage_path', $entry->fresh()->media[0]);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_a_second_run_does_not_store_the_attachment_twice(): void
    {
        $file = $this->localMedia('photo.png', $this->pngBytes());
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => null]]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);
        $first = $entry->fresh()->media[0]['storage_path'];

        // Retries after a partial failure are ordinary; they must finish
        // the job rather than duplicate what already landed.
        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $this->assertSame($first, $entry->fresh()->media[0]['storage_path']);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_a_scriptable_attachment_is_stored_but_not_served_inline(): void
    {
        $file = $this->localMedia('payload.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => 'image/svg+xml']]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $media = $entry->fresh()->media;

        // The bytes are kept — it's the customer's message, and the
        // client may need it. What must not happen is the object store
        // handing it back as a rendered document from a host we
        // control, which is a script written by whoever texted the
        // client running in our origin.
        Storage::disk('s3')->assertExists($media[0]['storage_path']);
        $this->assertStringContainsString('<script>', Storage::disk('s3')->get($media[0]['storage_path']));

        // Neutral extension. The other half of the defence — storing
        // the object with an `application/octet-stream` ContentType — is
        // not assertable here: Storage::fake() is a local filesystem
        // adapter that sniffs bytes on mimeType(), where S3 and
        // SeaweedFS return the metadata we set on put(). The extension
        // is what an object store infers from when metadata is absent
        // or ignored, so it is the half that survives a
        // misconfiguration, and it is the half this test pins.
        $this->assertStringEndsWith('.bin', $media[0]['storage_path']);
    }

    public function test_an_ordinary_attachment_keeps_its_type_and_extension(): void
    {
        // The neutering above is a denylist, not a blanket. A PDF or a
        // vCard should still open the way the recipient expects.
        $file = $this->localMedia('invoice.pdf', '%PDF-1.4 not really');
        $entry = $this->entryWithMedia([['url' => 'file://'.$file, 'content_type' => 'application/pdf']]);

        app()->call([new FetchMessageMediaJob($entry->id), 'handle']);

        $this->assertStringEndsWith('.pdf', $entry->fresh()->media[0]['storage_path']);
    }

    public function test_twilio_will_not_fetch_media_from_a_host_that_is_not_twilio(): void
    {
        Http::fake();

        config()->set('messaging.providers.twilio.account_sid', 'ACtest');
        config()->set('messaging.providers.twilio.auth_token', 'token');

        // The URL arrives inside a signature-verified webhook, so this
        // is defence in depth — but it is the layer that matters if the
        // auth token ever leaks, because without it a forged webhook
        // turns this into an SSRF proxy that helpfully attaches the
        // account's credentials to whatever host it is aimed at.
        $this->assertNull(
            app(TwilioProvider::class)->fetchMedia('https://attacker.example/steal'),
        );

        $this->assertNull(
            app(TwilioProvider::class)->fetchMedia('http://api.twilio.com/plaintext'),
        );

        Http::assertNothingSent();
    }

    public function test_twilio_fetches_media_with_the_account_credentials(): void
    {
        config()->set('messaging.providers.twilio.account_sid', 'ACtest');
        config()->set('messaging.providers.twilio.auth_token', 'token');

        Http::fake([
            'api.twilio.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $fetched = app(TwilioProvider::class)
            ->fetchMedia('https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages/SM1/Media/ME1');

        $this->assertNotNull($fetched);
        $this->assertSame('bytes', $fetched->contents);
        $this->assertSame('image/jpeg', $fetched->contentType);

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('ACtest:token'),
        ));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private function entryWithMedia(array $media): MessageEntry
    {
        $team = Team::factory()->create(['personal_team' => false]);

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
            'protocol' => 'mms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);

        return MessageEntry::create([
            'message_thread_id' => $thread->id,
            'team_id' => $team->id,
            'direction' => MessageEntry::DIRECTION_INBOUND,
            'from_address' => '+15551110000',
            'to_address' => '+15559990000',
            'body' => 'Here is the damage',
            'media' => $media,
            'provider' => 'log',
            'delivery_status' => MessageEntry::STATUS_RECEIVED,
            'occurred_at' => now(),
        ]);
    }

    private function localMedia(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/orbital-media-test-'.uniqid().'-'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /** Smallest thing PHP's mime detection will call a PNG. */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
