<?php

declare(strict_types=1);

namespace App\Services\Telephony;

/**
 * Effective call recording policy after walking the
 * extension → client → platform fallback chain. Always fully
 * populated — callers never have to check for nulls.
 *
 * The `source` field records where the *master toggle* came from
 * (extension override, client override, or platform default) for
 * audit display on the CallLog detail page.
 */
final class CallRecordingPolicy
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $format,
        public readonly int $retentionDays,
        public readonly string $storageDisk,
        public readonly bool $beepOnRecord,
        // Seconds between repeated notification beeps during a recording.
        // 0 = no periodic beep (beepOnRecord still controls the one at
        // the start of the call). Some jurisdictions require recurring
        // audible notification throughout the recording.
        public readonly int $beepIntervalSeconds,
        // Optional TTS message played at the start of a recorded call
        // (e.g. "This call may be monitored or recorded for quality
        // assurance."). Empty string = no disclosure played.
        public readonly string $disclosureMessage,
        // Asterisk-friendly prompt path (no file extension) pointing at
        // the rendered TTS audio file for `disclosureMessage`. Null
        // when the message is empty or hasn't been rendered yet. The
        // dialplan emits `Playback({path})` unconditionally — if the
        // file is missing at call time Asterisk logs a warning and
        // the call continues.
        public readonly ?string $disclosurePromptPath,
        public readonly string $source,
    ) {}

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'format' => $this->format,
            'retention_days' => $this->retentionDays,
            'storage_disk' => $this->storageDisk,
            'beep_on_record' => $this->beepOnRecord,
            'beep_interval_seconds' => $this->beepIntervalSeconds,
            'disclosure_message' => $this->disclosureMessage,
            'disclosure_prompt_path' => $this->disclosurePromptPath,
            'source' => $this->source,
        ];
    }
}
