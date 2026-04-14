<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Extension;
use App\Models\Team;

/**
 * Resolves "should this call be recorded?" and "what settings apply?"
 * by walking the extension → tenant → platform fallback chain.
 *
 * Resolution order:
 *
 *   1. extension.recording_mode
 *        - `always` → record regardless of tenant/platform
 *        - `never`  → never record, regardless of tenant/platform
 *        - `inherit` → defer to tenant
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
    ) {
    }

    /**
     * Resolve the effective recording policy for a specific extension.
     * If the extension doesn't belong to a team (platform-wide staff
     * extension), the tenant layer is skipped.
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
     * Resolve the effective policy for a whole team/tenant.
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
            source: $overrides === [] ? 'platform' : 'tenant',
        );
    }

    /**
     * Build the storage path for a given call's recording, relative to
     * the configured storage disk. Tenant-scoped so you can't clobber
     * one tenant's recordings with another's even if the call ID
     * collides (which it won't, since unique_id is globally unique).
     *
     * Pattern: tenants/{team_id}/{YYYY}/{MM}/{unique_id}.{ext}
     *
     * Tenant-scoped is defense in depth — enforcement of "can tenant X
     * read recording Y" still happens at the controller level against
     * the CallLog row's team_id.
     */
    public function pathFor(int $teamId, string $uniqueId, string $format = 'wav'): string
    {
        $month = date('Y/m');
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uniqueId);
        return "tenants/{$teamId}/{$month}/{$safeId}.{$format}";
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
