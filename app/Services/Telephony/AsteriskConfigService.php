<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

class AsteriskConfigService
{
    protected string $configPath;

    public function __construct()
    {
        $this->configPath = config('telephony.asterisk.config_path');
    }

    public function generatePjsipConf(?int $teamId = null): string
    {
        $trunks = SipTrunk::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->get();

        $extensions = Extension::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->get();

        return View::make('asterisk.pjsip', [
            'trunks' => $trunks,
            'extensions' => $extensions,
            'sipDomain' => config('telephony.asterisk.sip_domain'),
        ])->render();
    }

    public function generateExtensionsConf(?int $teamId = null): string
    {
        $extensions = Extension::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->with('assignable')
            ->get();

        $rules = RoutingRule::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        $queues = CallQueue::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->with(['members.extension', 'overflowAgent'])
            ->get();

        // Enrich each extension with its resolved recording policy so the
        // Blade template can emit MixMonitor() directives without having
        // to re-resolve tenant overrides per template render.
        $recording = app(CallRecordingService::class);
        foreach ($extensions as $ext) {
            $policy = $recording->resolveForExtension($ext);
            $ext->setAttribute('recording_policy', $policy);
        }

        return View::make('asterisk.extensions', [
            'extensions' => $extensions,
            'rules' => $rules,
            'queues' => $queues,
        ])->render();
    }

    public function generateQueuesConf(?int $teamId = null): string
    {
        $queues = CallQueue::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->with('members.extension')
            ->get();

        return View::make('asterisk.queues', [
            'queues' => $queues,
        ])->render();
    }

    public function writeConfigs(?int $teamId = null): void
    {
        File::ensureDirectoryExists($this->configPath);

        File::put(
            $this->configPath.'/pjsip_generated.conf',
            $this->generatePjsipConf($teamId)
        );

        File::put(
            $this->configPath.'/extensions_generated.conf',
            $this->generateExtensionsConf($teamId)
        );

        File::put(
            $this->configPath.'/queues_generated.conf',
            $this->generateQueuesConf($teamId)
        );
    }

    public function reloadAsterisk(): bool
    {
        $ami = app(AsteriskAmiService::class);

        return $ami->reload();
    }

    public function pushConfig(?int $teamId = null): bool
    {
        $this->writeConfigs($teamId);

        return $this->reloadAsterisk();
    }
}
