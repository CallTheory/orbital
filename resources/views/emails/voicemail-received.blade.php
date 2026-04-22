@php
    $status = $transcriptionStatus ?? 'skipped';
    $statusLine = match ($status) {
        'ok' => 'Transcribed via '.ucfirst(str_replace('_', ' ', (string) $transcriptionProvider)).'.',
        'failed' => 'Transcription failed — the audio recording is attached.',
        'skipped' => 'Transcription is disabled for this account. Turn it on from your admin settings to get text transcripts.',
        default => 'Transcription is pending.',
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New voicemail</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f2937; line-height: 1.5; max-width: 640px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #111827; margin: 0 0 16px;">New voicemail for {{ $team->name }}</h2>

    <table style="border-collapse: collapse; width: 100%; margin-bottom: 24px; font-size: 14px;">
        <tr>
            <td style="padding: 6px 0; color: #6b7280; width: 140px;">From</td>
            <td style="padding: 6px 0;">
                <strong>{{ $voicemail->caller_id_name ?: 'Unknown' }}</strong>
                @if($voicemail->caller_id_num)
                    &lt;{{ $voicemail->caller_id_num }}&gt;
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #6b7280;">Received</td>
            <td style="padding: 6px 0;">{{ $voicemail->created_at->format('l, F j Y \a\t g:i A T') }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #6b7280;">Duration</td>
            <td style="padding: 6px 0;">{{ $duration }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #6b7280;">Mailbox</td>
            <td style="padding: 6px 0;">{{ $voicemail->mailbox }}</td>
        </tr>
    </table>

    @if($transcript)
        <h3 style="color: #111827; margin: 0 0 8px; font-size: 16px;">Transcript</h3>
        <div style="background: #f9fafb; border-left: 4px solid #3b82f6; padding: 12px 16px; margin-bottom: 16px; white-space: pre-wrap; font-size: 15px;">{{ $transcript }}</div>
    @else
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; color: #78350f;">
            No transcript available for this message.
        </div>
    @endif

    <p style="font-size: 13px; color: #6b7280; margin-top: 24px;">
        {{ $statusLine }} The audio is attached as <code>voicemail.wav</code>.
    </p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
    <p style="font-size: 12px; color: #9ca3af;">
        This message was received by Orbital on behalf of {{ $team->name }}.
    </p>
</body>
</html>
