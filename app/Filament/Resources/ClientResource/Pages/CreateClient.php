<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Jobs\RenderDisclosurePromptJob;
use App\Models\Team;
use App\Services\Clients\ClientProvisioner;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Team
    {
        /** @var Team $team */
        $team = Team::forceCreate([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'account_number' => $data['account_number'] ?? null,
            'personal_team' => false,
            'max_concurrent_calls' => $data['max_concurrent_calls'] ?? null,
            'suspended_at' => $data['suspended_at'] ?? null,
            'recording_overrides' => $this->cleanRecordingOverrides($data['recording_overrides'] ?? null),
        ]);

        $owner = \App\Models\User::find($data['user_id']);
        app(ClientProvisioner::class)->provision($team, $owner);

        // If the new client set a custom disclosure message, kick off
        // TTS rendering now so Asterisk has the audio file ready on
        // the first call. Idempotent by content hash.
        $msg = (string) ($team->recording_overrides['disclosure_message'] ?? '');
        if ($msg !== '') {
            RenderDisclosurePromptJob::dispatch($msg);
        }

        return $team;
    }

    /**
     * Same cleanup rules as EditClient — empty-string sentinel values
     * become absent keys so the CallRecordingService resolver falls
     * through to the platform default for those fields.
     */
    protected function cleanRecordingOverrides(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $cleaned = [];
        foreach ($raw as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array($key, ['enabled', 'beep_on_record'], true)) {
                $cleaned[$key] = (bool) $value;
            } elseif (in_array($key, ['retention_days', 'beep_interval_seconds'], true)) {
                $cleaned[$key] = (int) $value;
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned === [] ? null : $cleaned;
    }
}
