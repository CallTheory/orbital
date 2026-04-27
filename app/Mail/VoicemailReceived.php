<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Voicemail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Client-facing voicemail notification. One email per voicemail row
 * with the transcript inline and the WAV attached.
 *
 * Subject includes caller ID so clients can triage without opening
 * the email. Transcript status footer tells the operator whether
 * the transcript is trustworthy, degraded, or skipped entirely.
 */
class VoicemailReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Voicemail $voicemail,
    ) {}

    public function envelope(): Envelope
    {
        $caller = $this->voicemail->caller_id_num ?: 'Unknown caller';
        $duration = $this->formatDuration($this->voicemail->duration_seconds);

        return new Envelope(
            subject: "New voicemail from {$caller} ({$duration})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.voicemail-received',
            with: [
                'voicemail' => $this->voicemail,
                'team' => $this->voicemail->team,
                'transcript' => $this->voicemail->transcript,
                'transcriptionStatus' => $this->voicemail->transcription_status,
                'transcriptionProvider' => $this->voicemail->transcription_provider,
                'duration' => $this->formatDuration($this->voicemail->duration_seconds),
            ],
        );
    }

    /**
     * @return array<Attachment>
     */
    public function attachments(): array
    {
        $path = (string) $this->voicemail->recording_path;
        if ($path === '' || ! is_readable($path)) {
            return [];
        }

        return [
            Attachment::fromPath($path)
                ->as('voicemail.wav')
                ->withMime('audio/wav'),
        ];
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d', $m, $s);
    }
}
