<?php

declare(strict_types=1);

namespace App\Services\Voicemail;

/**
 * Per-provider voicemail transcription contract.
 *
 * Each driver gets the absolute path to a WAV file on the local
 * filesystem (the asterisk-voicemail-spool shared volume, mounted
 * into the Laravel app container) and returns plain text. No
 * structured output — the driver is responsible for normalising
 * whatever provider-shape JSON into a single transcript string.
 *
 * Drivers throw on failure so the caller's queued job can mark
 * the Voicemail row as `transcription_status=failed` and bubble
 * the error message up to the admin UI.
 */
interface TranscriptionDriver
{
    public function transcribe(string $wavPath): string;
}
