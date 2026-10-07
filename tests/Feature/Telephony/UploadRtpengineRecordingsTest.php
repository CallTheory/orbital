<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Console\Commands\UploadRtpengineRecordings;
use App\Jobs\UploadCallRecordingJob;
use App\Models\CallRecording;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the uploader to what rtpengine's recording-daemon (mr26)
 * actually writes with `output-mixed` + `output-pattern =
 * call-id-%c--leg-%t`: one `call-id-<Call-ID>--leg-mix.wav` per call,
 * written in place for the whole call with no `.tmp` marker.
 */
class UploadRtpengineRecordingsTest extends TestCase
{
    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spool = sys_get_temp_dir().'/rtpengine-spool-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->spool);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);

        parent::tearDown();
    }

    public function test_parses_mixed_recording_with_real_world_call_id(): void
    {
        $parsed = (new UploadRtpengineRecordings)->parseFilename('call-id-a84b4c76e66710@10.0.0.5--leg-mix.wav');

        $this->assertSame([
            'sip_call_id' => 'a84b4c76e66710@10.0.0.5',
            'direction' => CallRecording::DIRECTION_MIXED,
            'leg_uuid' => 'a84b4c76e66710@10.0.0.5',
        ], $parsed);
    }

    public function test_collision_suffix_is_kept_in_leg_uuid(): void
    {
        $parsed = (new UploadRtpengineRecordings)->parseFilename('call-id-abc.def--leg-mix-2.wav');

        $this->assertSame('abc.def', $parsed['sip_call_id']);
        $this->assertSame('abc.def-2', $parsed['leg_uuid']);
    }

    public function test_call_id_containing_the_separator_splits_on_last_occurrence(): void
    {
        $parsed = (new UploadRtpengineRecordings)->parseFilename('call-id-x--leg-y--leg-mix.mp3');

        $this->assertSame('x--leg-y', $parsed['sip_call_id']);
    }

    #[DataProvider('unparseableNames')]
    public function test_rejects_names_the_configured_daemon_does_not_produce(string $name): void
    {
        $this->assertNull((new UploadRtpengineRecordings)->parseFilename($name));
    }

    public static function unparseableNames(): array
    {
        return [
            'daemon default pattern' => ['abc@host-5f3a9c1e-mix.wav'],
            'per-source SSRC output' => ['call-id-abc--leg-1a2b3c4d.wav'],
            'old recv shorthand' => ['abc-recv.wav'],
            'empty leg' => ['call-id-abc--leg-.wav'],
            'unknown extension' => ['call-id-abc--leg-mix.pcap'],
        ];
    }

    public function test_drain_skips_recordings_still_being_written(): void
    {
        Bus::fake();

        $finished = $this->recording('call-id-done@pbx--leg-mix.wav', ageSeconds: UploadRtpengineRecordings::SETTLE_SECONDS + 5);
        $this->recording('call-id-live@pbx--leg-mix.wav', ageSeconds: 10);

        $this->assertSame(1, $this->drain());

        Bus::assertDispatchedTimes(UploadCallRecordingJob::class, 1);
        Bus::assertDispatched(UploadCallRecordingJob::class, fn (UploadCallRecordingJob $job) => $job->sourcePath === $finished
            && $job->direction === CallRecording::DIRECTION_MIXED);
    }

    public function test_drain_leaves_unparseable_files_in_place(): void
    {
        Bus::fake();
        Log::spy();

        $stray = $this->recording('abc@host-5f3a9c1e-mix.wav', ageSeconds: UploadRtpengineRecordings::SETTLE_SECONDS + 5);

        $this->assertSame(0, $this->drain());

        Bus::assertNothingDispatched();
        $this->assertFileExists($stray);
        Log::shouldHaveReceived('warning')->once()->with('rtpengine spool: could not parse filename', \Mockery::on(fn (array $context) => $context['file'] === $stray));
    }

    private function recording(string $name, int $ageSeconds): string
    {
        $path = "{$this->spool}/{$name}";
        file_put_contents($path, 'RIFF');
        touch($path, time() - $ageSeconds);

        return $path;
    }

    private function drain(): int
    {
        $command = new class extends UploadRtpengineRecordings
        {
            public function drainFor(string $base): int
            {
                return $this->drain($base, 'edge-test');
            }
        };

        return $command->drainFor($this->spool);
    }
}
