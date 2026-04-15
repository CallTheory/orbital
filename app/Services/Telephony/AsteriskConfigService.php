<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/**
 * Generates Asterisk's static config files from Eloquent state.
 *
 * **Per-tenant dialplan layout (Phase 2).** Every tenant's dialplan
 * lives in its own include file under
 * `{config_path}/tenants/{team_id}-dialplan.conf`, with a single
 * `dialplan_index.conf` enumerating them and a `from-trunk.conf`
 * holding the inbound dispatcher. Reload churn is scoped: editing
 * a single tenant's RoutingRule rewrites just one tenant file plus
 * the dispatcher and triggers `dialplan reload` instead of
 * `core reload`.
 *
 * Endpoints and queues now live in Asterisk Realtime tables (Phase
 * 3/4) and are pulled by `res_sorcery_realtime` on call setup, so
 * this service only writes dialplan files — no more pjsip.conf or
 * queues.conf generation.
 */
class AsteriskConfigService
{
    protected string $configPath;

    public function __construct()
    {
        $this->configPath = config('telephony.asterisk.config_path');
    }

    // ── Per-tenant dialplan generators ──────────────────────────────

    /**
     * Render one tenant's dialplan context. Includes that tenant's
     * extensions + queues only — no inbound routing rules (those
     * live in the from-trunk dispatcher that Goto's into the right
     * tenant context).
     */
    public function generateDialplanForTenant(int $teamId): string
    {
        $team = Team::withoutGlobalScopes()->findOrFail($teamId);

        $extensions = Extension::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->with('assignable')
            ->get();

        $queues = CallQueue::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('overflowAgent.extensions')
            ->get();

        // Enrich each extension with its resolved recording policy
        // so the mix-monitor partial can emit MixMonitor() inline
        // without a per-render policy lookup.
        $recording = app(CallRecordingService::class);
        foreach ($extensions as $ext) {
            $policy = $recording->resolveForExtension($ext);
            $ext->setAttribute('recording_policy', $policy);
        }

        return View::make('asterisk.tenant-dialplan', [
            'team' => $team,
            'context' => $team->dialplanContext(),
            'extensions' => $extensions,
            'queues' => $queues,
        ])->render();
    }

    /**
     * Render the inbound trunk dispatcher. Pulls every active
     * routing rule across all tenants — the dispatcher is global
     * because trunks are shared infrastructure and the DID match
     * is what determines which tenant context the call lands in.
     */
    public function generateFromTrunkDispatcher(): string
    {
        $rules = RoutingRule::withoutGlobalScopes()
            ->where('is_active', true)
            ->with('team')
            ->orderBy('priority')
            ->get();

        return View::make('asterisk.from-trunk', [
            'rules' => $rules,
        ])->render();
    }

    /**
     * Render the dialplan index. Lists every active tenant's
     * dialplan file by name so Asterisk's `#include` directives
     * pull them in (Asterisk doesn't support include globbing).
     */
    public function generateDialplanIndex(): string
    {
        $teamIds = Team::query()
            ->where('personal_team', false)
            ->pluck('id')
            ->all();

        return View::make('asterisk.dialplan-index', [
            'teamIds' => $teamIds,
        ])->render();
    }

    // ── Per-tenant write paths ──────────────────────────────────────

    /**
     * Write a single tenant's dialplan file plus the from-trunk
     * dispatcher (since that file references all tenants and a
     * tenant's own routing rules can change which DIDs land where).
     * Does NOT touch the dialplan index — that's only regenerated
     * when tenants are created or deleted.
     */
    public function writeDialplanForTenant(int $teamId): void
    {
        $tenantDir = $this->configPath.'/tenants';
        File::ensureDirectoryExists($tenantDir);

        File::put(
            $tenantDir."/{$teamId}-dialplan.conf",
            $this->generateDialplanForTenant($teamId),
        );

        File::put(
            $this->configPath.'/from-trunk.conf',
            $this->generateFromTrunkDispatcher(),
        );
    }

    /**
     * Regenerate the dialplan index. Called when a tenant is
     * created or deleted — those events change which include
     * directives the index file emits, but in-place tenant edits
     * never touch it.
     */
    public function writeDialplanIndex(): void
    {
        File::ensureDirectoryExists($this->configPath);

        File::put(
            $this->configPath.'/dialplan_index.conf',
            $this->generateDialplanIndex(),
        );
    }

    /**
     * Remove a tenant's dialplan file from disk. Called by the
     * Team-deleted observer so the next dialplan reload doesn't
     * reference a tenant whose data is gone.
     */
    public function deleteDialplanForTenant(int $teamId): void
    {
        $path = $this->configPath."/tenants/{$teamId}-dialplan.conf";
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Full regen of every dialplan artefact. Used by the
     * `orbital:generate-config` artisan command and as a last-resort
     * recovery for drift. Walks every tenant, writes a per-tenant
     * file each, then the dispatcher and index.
     */
    public function writeAllDialplans(): void
    {
        $tenantDir = $this->configPath.'/tenants';
        File::ensureDirectoryExists($tenantDir);

        $teamIds = Team::query()
            ->where('personal_team', false)
            ->pluck('id')
            ->all();

        foreach ($teamIds as $teamId) {
            File::put(
                $tenantDir."/{$teamId}-dialplan.conf",
                $this->generateDialplanForTenant($teamId),
            );
        }

        File::put(
            $this->configPath.'/from-trunk.conf',
            $this->generateFromTrunkDispatcher(),
        );

        File::put(
            $this->configPath.'/dialplan_index.conf',
            $this->generateDialplanIndex(),
        );
    }

    public function reloadAsterisk(): bool
    {
        $ami = app(AsteriskAmiService::class);

        return $ami->reload();
    }

    /**
     * Cheaper than `reloadAsterisk()` — only re-parses the dialplan
     * (`dialplan reload`), not the whole config. Used by the
     * per-tenant write path so an edit to one tenant's RoutingRule
     * doesn't churn pjsip / queues / codec config across the whole
     * Asterisk process.
     */
    public function reloadDialplan(): bool
    {
        return app(AsteriskAmiService::class)->reloadDialplan();
    }

    public function pushConfig(?int $teamId = null): bool
    {
        if ($teamId !== null) {
            $this->writeDialplanForTenant($teamId);
        } else {
            $this->writeAllDialplans();
            $this->writeDialplanIndex();
        }

        return $this->reloadAsterisk();
    }
}
