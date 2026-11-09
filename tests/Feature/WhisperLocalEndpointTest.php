<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Services\Voicemail\VoicemailTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Local transcription goes to whatever WHISPER_LOCAL_URL names: the Helm
 * chart's Service, not the compose hostname.
 */
class WhisperLocalEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_whisper_is_called_at_the_configured_url(): void
    {
        config(['services.whisper_local.url' => 'http://orbital-orbital-whisper-local:9700']);
        Http::fake(['*' => Http::response(['text' => ' Please call me back. '])]);
        $wav = tempnam(sys_get_temp_dir(), 'vm');
        file_put_contents($wav, 'RIFF');

        $team = Team::factory()->create([
            'voicemail_transcription_provider' => 'whisper_local',
            'voicemail_transcription_config' => ['model' => 'base.en'],
        ]);

        try {
            $text = app(VoicemailTranscriber::class)->transcribeFor($team, $wav);
        } finally {
            unlink($wav);
        }

        $this->assertSame('Please call me back.', $text);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://orbital-orbital-whisper-local:9700/inference');
    }
}
