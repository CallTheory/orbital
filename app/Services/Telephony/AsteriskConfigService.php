<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AsteriskBackend;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Generates Asterisk's static config files from Eloquent state.
 *
 * **Per-client dialplan layout (Phase 2).** Every client's dialplan
 * lives in its own include file under
 * `{config_path}/clients/{team_id}-dialplan.conf`, with a single
 * `dialplan_index.conf` enumerating them and a `from-trunk.conf`
 * holding the inbound dispatcher. Reload churn is scoped: editing
 * a single client's RoutingRule rewrites just one client file plus
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
    // ── Per-client dialplan generators ──────────────────────────────

    /**
     * Render one client's dialplan context. Includes that client's
     * extensions + queues only — no inbound routing rules (those
     * live in the from-trunk dispatcher that Goto's into the right
     * client context).
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

        return View::make('asterisk.client-dialplan', [
            'team' => $team,
            'context' => $team->dialplanContext(),
            'extensions' => $extensions,
            'queues' => $queues,
        ])->render();
    }

    /**
     * Render the inbound trunk dispatcher. Pulls every active
     * routing rule across all clients — the dispatcher is global
     * because trunks are shared infrastructure and the DID match
     * is what determines which client context the call lands in.
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
     * Render the dialplan index. Lists every active client's
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

    /**
     * Render the [internal] context — dialling context for platform
     * staff softphones. Contains all extensions across all clients
     * so an operator can dial any AI agent or coworker extension
     * without knowing the client context.
     */
    public function generateInternalContext(): string
    {
        $extensions = Extension::withoutGlobalScopes()
            ->where('is_active', true)
            ->with('assignable')
            ->get();

        $recording = app(CallRecordingService::class);
        foreach ($extensions as $ext) {
            $ext->setAttribute('recording_policy', $recording->resolveForExtension($ext));
        }

        $queues = CallQueue::withoutGlobalScopes()
            ->with('overflowAgent.extensions')
            ->get();

        $rules = RoutingRule::withoutGlobalScopes()
            ->where('is_active', true)
            ->with('team')
            ->orderBy('priority')
            ->get();

        return View::make('asterisk.extensions', [
            'extensions' => $extensions,
            'queues' => $queues,
            'rules' => $rules,
            // Guard the "operator dials a DID and lands in from-trunk"
            // pass-through behind a config flag. Off in production so a
            // human operator can't self-originate calls that look like
            // they came from outside; on in dev for testing templates
            // without a real phone.
            'internalDidSimulation' => (bool) config('telephony.asterisk.internal_did_simulation', false),
        ])->render();
    }

    // ── Per-client write paths ──────────────────────────────────────

    /**
     * Write a single client's dialplan file plus the from-trunk
     * dispatcher (since that file references all clients and a
     * client's own routing rules can change which DIDs land where).
     * Does NOT touch the dialplan index — that's only regenerated
     * when clients are created or deleted.
     */
    public function writeDialplanForTenant(int $teamId): void
    {
        $disk = Storage::disk('asterisk-config');

        $disk->put(
            "clients/{$teamId}-dialplan.conf",
            $this->generateDialplanForTenant($teamId),
        );

        $disk->put(
            'from-trunk.conf',
            $this->generateFromTrunkDispatcher(),
        );

        $this->bumpVersion();
    }

    /**
     * Regenerate the dialplan index. Called when a client is
     * created or deleted — those events change which include
     * directives the index file emits, but in-place client edits
     * never touch it.
     */
    public function writeDialplanIndex(): void
    {
        Storage::disk('asterisk-config')->put(
            'dialplan_index.conf',
            $this->generateDialplanIndex(),
        );

        $this->bumpVersion();
    }

    /**
     * Remove a client's dialplan file from disk. Called by the
     * Team-deleted observer so the next dialplan reload doesn't
     * reference a client whose data is gone.
     */
    public function deleteDialplanForTenant(int $teamId): void
    {
        $disk = Storage::disk('asterisk-config');
        $key = "clients/{$teamId}-dialplan.conf";

        if ($disk->exists($key)) {
            $disk->delete($key);
        }

        $this->bumpVersion();
    }

    /**
     * Full regen of every dialplan artefact. Used by the
     * `orbital:generate-config` artisan command and as a last-resort
     * recovery for drift. Walks every client, writes a per-client
     * file each, then the dispatcher and index.
     */
    public function writeAllDialplans(): void
    {
        $disk = Storage::disk('asterisk-config');

        $teamIds = Team::query()
            ->where('personal_team', false)
            ->pluck('id')
            ->all();

        foreach ($teamIds as $teamId) {
            $disk->put(
                "clients/{$teamId}-dialplan.conf",
                $this->generateDialplanForTenant($teamId),
            );
        }

        $disk->put(
            'from-trunk.conf',
            $this->generateFromTrunkDispatcher(),
        );

        $disk->put(
            'extensions_generated.conf',
            $this->generateInternalContext(),
        );

        $disk->put(
            'dialplan_index.conf',
            $this->generateDialplanIndex(),
        );

        $disk->put(
            'voicemail.conf',
            $this->generateVoicemail(),
        );

        $this->bumpVersion();
    }

    /**
     * Render voicemail.conf with one mailbox per client that has a
     * `destination_type=voicemail` routing rule. Mailbox number =
     * client account_number; recordings go to the client's primary
     * contact email with the audio attached.
     *
     * The rendered file is bind-mounted over the stock
     * /etc/asterisk/voicemail.conf in the asterisk-1/asterisk-2
     * compose services. A `voicemail reload` via AMI after a write
     * picks up new mailboxes without an Asterisk restart.
     */
    public function generateVoicemail(): string
    {
        // Pull every team that has at least one active voicemail
        // routing rule. One mailbox per team regardless of how many
        // voicemail rules point at it — the mailbox is the client's,
        // not the rule's.
        $teamIds = RoutingRule::query()
            ->where('destination_type', 'voicemail')
            ->where('is_active', true)
            ->pluck('team_id')
            ->filter()
            ->unique()
            ->values();

        $mailboxes = [];
        foreach ($teamIds as $teamId) {
            $team = Team::withoutGlobalScopes()->find($teamId);
            if (! $team || ! $team->account_number) {
                continue;
            }
            $contact = $team->owner;
            $mailboxes[] = [
                // Mailbox number is the client's account_number so
                // it's stable across DID / rule changes and a human
                // operator can say "mailbox 100001" on the phone.
                'mailbox' => (string) $team->account_number,
                // Password isn't used for IMAP-style retrieval — we
                // deliver via email, not phone-based mailbox review —
                // so a fixed placeholder is fine. Callers can't
                // enter it anyway (no *98 login in the dialplan).
                'password' => '0000',
                'fullname' => $team->name,
                'email' => $contact?->email ?? '',
            ];
        }

        return View::make('asterisk.voicemail', [
            'mailboxes' => $mailboxes,
        ])->render();
    }

    /**
     * Reload configuration on EVERY registered Asterisk backend —
     * not just the primary. With multiple nodes sharing a bind-
     * mounted config directory, writing a new file only takes
     * effect on nodes that get a `dialplan reload` (or `core
     * reload`) AMI command. Fan-out keeps them all in lock-step.
     *
     * Returns true only when every active backend accepted the
     * command. Partial failure logs per-node so the operator can
     * see which node didn't pick up the change.
     */
    public function reloadAsterisk(): bool
    {
        return $this->commandOnAllBackends('core reload');
    }

    /**
     * Cheaper than `reloadAsterisk()` — only re-parses the dialplan
     * (`dialplan reload`), not the whole config. Used by the
     * per-client write path so an edit to one client's RoutingRule
     * doesn't churn pjsip / queues / codec config across the whole
     * Asterisk process. Fan-out to every active backend.
     */
    public function reloadDialplan(): bool
    {
        return $this->commandOnAllBackends('dialplan reload');
    }

    /**
     * Helper: send a single AMI Command to every active
     * AsteriskBackend row. Falls back to the legacy single-host
     * AMI config when no backends are registered (fresh installs,
     * non-HA dev setups) so the service still works before the
     * registry is seeded.
     */
    protected function commandOnAllBackends(string $command): bool
    {
        $ami = app(AsteriskAmiService::class);
        $backends = AsteriskBackend::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();

        if ($backends->isEmpty()) {
            // Legacy single-host path — use the original AMI
            // connection the service was configured with via env.
            return match ($command) {
                'core reload' => $ami->reload(),
                'dialplan reload' => $ami->reloadDialplan(),
                default => false,
            };
        }

        $allOk = true;
        foreach ($backends as $backend) {
            $ok = $ami->commandOn($backend->amiHost(), $backend->ami_port, $command);
            if (! $ok) {
                Log::warning('asterisk-config: reload failed on backend', [
                    'backend' => $backend->hostname,
                    'command' => $command,
                ]);
                $allOk = false;
            }
        }

        return $allOk;
    }

    /**
     * Write a fresh version token to the asterisk-config disk so the
     * Asterisk-side sync sidecar (which polls/watches the S3 bucket
     * in Kubernetes deployments) knows a reload is due. No-op in
     * spirit on Sail, where the bind mount is already shared, but
     * kept unconditional so the two environments behave the same
     * way and the token stays meaningful if something starts
     * consuming it locally too.
     */
    private function bumpVersion(): void
    {
        Storage::disk('asterisk-config')->put('version.txt', (string) Str::uuid());
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
