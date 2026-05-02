<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallRecording;
use App\Models\Extension;
use App\Models\Team;

/**
 * Resolves "should this call be recorded?" and "what settings apply?"
 * by walking the extension → client → platform fallback chain.
 *
 * Resolution order:
 *
 *   1. extension.recording_mode
 *        - `always` → record regardless of client/platform
 *        - `never`  → never record, regardless of client/platform
 *        - `inherit` → defer to client
 *   2. team.recording_overrides (JSON, per-key)
 *        - `enabled` key present → that value wins for the master toggle
 *        - other keys (`format`, `retention_days`, etc) override individually
 *   3. config('telephony.recording.*') as the platform fallback
 *
 * The resolver always returns a fully-populated CallRecordingPolicy
 * so callers don't need to worry about falling back to defaults
 * themselves.
 */
class CallRecordingService
{
    public function __construct(
        protected readonly DisclosureRenderer $disclosureRenderer,
    ) {}

    /**
     * Resolve the effective recording policy for a specific extension.
     * If the extension doesn't belong to a team (platform-wide staff
     * extension), the client layer is skipped.
     */
    public function resolveForExtension(Extension $extension): CallRecordingPolicy
    {
        // Extension-level overrides short-circuit everything.
        $mode = $extension->recording_mode ?? 'inherit';

        if ($mode === 'never') {
            $disclosure = $this->platformDisclosureMessage();

            return new CallRecordingPolicy(
                enabled: false,
                format: $this->platformFormat(),
                retentionDays: $this->platformRetentionDays(),
                storageDisk: $this->platformStorageDisk(),
                beepOnRecord: $this->platformBeep(),
                beepIntervalSeconds: $this->platformBeepInterval(),
                disclosureMessage: $disclosure,
                disclosurePromptPath: $this->disclosureRenderer->asteriskPromptPath($disclosure),
                source: 'extension',
            );
        }

        $team = $extension->team;
        $teamPolicy = $team ? $this->resolveForTeam($team) : $this->platformPolicy('platform');

        if ($mode === 'always') {
            return new CallRecordingPolicy(
                enabled: true,
                format: $teamPolicy->format,
                retentionDays: $teamPolicy->retentionDays,
                storageDisk: $teamPolicy->storageDisk,
                beepOnRecord: $teamPolicy->beepOnRecord,
                beepIntervalSeconds: $teamPolicy->beepIntervalSeconds,
                disclosureMessage: $teamPolicy->disclosureMessage,
                disclosurePromptPath: $teamPolicy->disclosurePromptPath,
                source: 'extension',
            );
        }

        // `inherit` → use the team policy as-is.
        return $teamPolicy;
    }

    /**
     * Resolve the effective policy for a whole team/client.
     */
    public function resolveForTeam(Team $team): CallRecordingPolicy
    {
        $overrides = $team->recording_overrides ?? [];
        if (! is_array($overrides)) {
            $overrides = [];
        }

        $disclosure = $this->pickString($overrides, 'disclosure_message', $this->platformDisclosureMessage());

        return new CallRecordingPolicy(
            enabled: $this->pickBool($overrides, 'enabled', $this->platformEnabled()),
            format: $this->pickString($overrides, 'format', $this->platformFormat()),
            retentionDays: $this->pickInt($overrides, 'retention_days', $this->platformRetentionDays()),
            storageDisk: $this->pickString($overrides, 'storage_disk', $this->platformStorageDisk()),
            beepOnRecord: $this->pickBool($overrides, 'beep_on_record', $this->platformBeep()),
            beepIntervalSeconds: $this->pickInt($overrides, 'beep_interval_seconds', $this->platformBeepInterval()),
            disclosureMessage: $disclosure,
            disclosurePromptPath: $this->disclosureRenderer->asteriskPromptPath($disclosure),
            source: $overrides === [] ? 'platform' : 'client',
        );
    }

    /**
     * Resolve the recording **surfaces** that apply to a given call.
     * Returns the array of `CallRecording::SOURCE_*` values that should
     * write a row when the call ends.
     *
     *  - rtpengine_edge: every call that traverses SIP (i.e. crosses
     *    Kamailio + rtpengine). External callers, operator-to-operator,
     *    outbound dial-out — all of these.
     *  - livekit_egress: every call that involves a LiveKit room.
     *    External-caller-to-AI, operator-to-AI handoff, AI training
     *    sessions, multi-participant rooms.
     *
     * Calls can be on more than one surface — an external-caller-to-AI
     * call shows up on both. The returned list is ordered: rtpengine
     * first, then livekit. Empty list = recording disabled by policy.
     *
     * @param  array{has_livekit_room?: bool, has_sip_dialog?: bool}  $context
     *                                                                          Per-call hints. Defaults assume both surfaces apply, which is
     *                                                                          correct for the most common path (external SIP → AI in LK room).
     * @return list<string>
     */
    public function resolveSurfaces(CallRecordingPolicy $policy, array $context = []): array
    {
        if (! $policy->enabled) {
            return [];
        }

        $hasSip = $context['has_sip_dialog'] ?? true;
        $hasLk = $context['has_livekit_room'] ?? true;

        $surfaces = [];
        if ($hasSip) {
            $surfaces[] = CallRecording::SOURCE_RTPENGINE_EDGE;
        }
        if ($hasLk) {
            $surfaces[] = CallRecording::SOURCE_LIVEKIT_EGRESS;
        }

        return $surfaces;
    }

    /**
     * Render the `X-Orbital-Record-*` SIP header set Kamailio reads
     * to drive rtpengine NG `record-call=yes` + metadata. The recording
     * pipeline keys off these — the headers carry policy + correlation
     * context across the SIP dialog so the upload watcher can stitch
     * spool files back to a `CallLog`.
     *
     * Returned shape is the literal header name → value map. Caller
     * (CallOriginationService / dialplan emitter) decides where to
     * attach them.
     *
     * @param  array{tenant_id?: int, call_uuid?: string, leg_role?: string}  $context
     * @return array<string, string>
     */
    public function recordingHeaders(CallRecordingPolicy $policy, array $context = []): array
    {
        if (! $policy->enabled) {
            return ['X-Orbital-Record-Enabled' => 'no'];
        }

        $headers = [
            'X-Orbital-Record-Enabled' => 'yes',
            'X-Orbital-Record-Format' => $policy->format,
        ];

        if (isset($context['tenant_id'])) {
            $headers['X-Orbital-Tenant-Id'] = (string) $context['tenant_id'];
        }
        if (isset($context['call_uuid'])) {
            $headers['X-Orbital-Call-Uuid'] = $context['call_uuid'];
        }
        if (isset($context['leg_role'])) {
            $headers['X-Orbital-Record-Leg-Role'] = $context['leg_role'];
        }

        return $headers;
    }

    /**
     * Build the storage path for a given call's recording, relative to
     * the configured storage disk. Client-scoped so you can't clobber
     * one client's recordings with another's even if the call ID
     * collides (which it won't, since unique_id is globally unique).
     *
     * Pattern: clients/{team_id}/{YYYY}/{MM}/{unique_id}-{direction}.{ext}
     *
     * Client-scoped is defense in depth — enforcement of "can client X
     * read recording Y" still happens at the controller level against
     * the CallLog row's team_id.
     */
    public function pathFor(int $teamId, string $uniqueId, string $format = 'wav', ?string $direction = null): string
    {
        $month = date('Y/m');
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uniqueId);
        $directionSegment = $direction !== null ? '-'.preg_replace('/[^a-z_]/', '_', $direction) : '';

        return "clients/{$teamId}/{$month}/{$safeId}{$directionSegment}.{$format}";
    }

    protected function platformPolicy(string $source): CallRecordingPolicy
    {
        $disclosure = $this->platformDisclosureMessage();

        return new CallRecordingPolicy(
            enabled: $this->platformEnabled(),
            format: $this->platformFormat(),
            retentionDays: $this->platformRetentionDays(),
            storageDisk: $this->platformStorageDisk(),
            beepOnRecord: $this->platformBeep(),
            beepIntervalSeconds: $this->platformBeepInterval(),
            disclosureMessage: $disclosure,
            disclosurePromptPath: $this->disclosureRenderer->asteriskPromptPath($disclosure),
            source: $source,
        );
    }

    protected function platformEnabled(): bool
    {
        return (bool) config('telephony.recording.enabled', true);
    }

    protected function platformFormat(): string
    {
        return (string) config('telephony.recording.format', 'wav');
    }

    protected function platformRetentionDays(): int
    {
        return (int) config('telephony.recording.retention_days', 90);
    }

    protected function platformStorageDisk(): string
    {
        return (string) config('telephony.recording.storage_disk', 's3');
    }

    protected function platformBeep(): bool
    {
        return (bool) config('telephony.recording.beep_on_record', false);
    }

    protected function platformBeepInterval(): int
    {
        return (int) config('telephony.recording.beep_interval_seconds', 0);
    }

    protected function platformDisclosureMessage(): string
    {
        return (string) config('telephony.recording.disclosure_message', '');
    }

    protected function pickBool(array $overrides, string $key, bool $default): bool
    {
        return array_key_exists($key, $overrides) ? (bool) $overrides[$key] : $default;
    }

    protected function pickInt(array $overrides, string $key, int $default): int
    {
        return array_key_exists($key, $overrides) ? (int) $overrides[$key] : $default;
    }

    protected function pickString(array $overrides, string $key, string $default): string
    {
        return array_key_exists($key, $overrides) ? (string) $overrides[$key] : $default;
    }
}
