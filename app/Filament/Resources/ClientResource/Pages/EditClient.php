<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Jobs\RenderDisclosurePromptJob;
use App\Services\Clients\ClientPermissionGatekeeper;
use App\Services\Telephony\AsteriskConfigService;
use App\Services\Telephony\VoicemailGreetingRenderer;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $navigationLabel = 'General';

    public function getTitle(): string
    {
        return 'General';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Strip empty-string sentinel values out of recording_overrides
     * before save so the CallRecordingService resolver only sees keys
     * that were actually set by the operator. Empty inputs on the form
     * mean "inherit platform default" — we encode that as "the key
     * isn't present in the JSON at all".
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->cleanRecordingOverrides($data);
    }

    protected function cleanRecordingOverrides(array $data): array
    {
        if (! isset($data['recording_overrides']) || ! is_array($data['recording_overrides'])) {
            return $data;
        }

        $cleaned = [];
        foreach ($data['recording_overrides'] as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            // The form uses '1' / '0' for tri-state selects; cast back
            // to real booleans/ints so the resolver's type checks work.
            if (in_array($key, ['enabled', 'beep_on_record'], true)) {
                $cleaned[$key] = (bool) $value;
            } elseif (in_array($key, ['retention_days', 'beep_interval_seconds'], true)) {
                $cleaned[$key] = (int) $value;
            } else {
                $cleaned[$key] = $value;
            }
        }

        $data['recording_overrides'] = $cleaned === [] ? null : $cleaned;

        return $data;
    }

    /**
     * After save, push the allow-list through the gatekeeper. The form
     * field is dehydrated(false) so it doesn't round-trip into the model —
     * we pull it from the form state directly.
     */
    protected function afterSave(): void
    {
        $state = $this->form->getRawState();
        $permissions = $state['allowed_permissions'] ?? null;

        if (is_array($permissions)) {
            app(ClientPermissionGatekeeper::class)
                ->setTenantAllowList($this->record, $permissions);
        }

        // Re-render the disclosure TTS if the client has a custom
        // message. Idempotent by content hash, so this is a no-op when
        // the message didn't actually change.
        $msg = (string) ($this->record->recording_overrides['disclosure_message'] ?? '');
        if ($msg !== '') {
            RenderDisclosurePromptJob::dispatch($msg);
        }

        // Voicemail greeting — render the TTS WAV or remove an old
        // file when the client switched back to Asterisk's default.
        // Synchronous (not queued) because the dialplan regen below
        // needs to see the file state on disk to emit the right
        // Playback() vs stock VoiceMail() branch.
        $renderer = app(VoicemailGreetingRenderer::class);
        $mode = (string) ($this->record->voicemail_greeting_mode ?? 'asterisk_default');
        if ($mode === 'custom_tts') {
            $renderer->ensureRenderedForTenant($this->record);
        } else {
            $renderer->deleteFor($this->record);
        }

        // Regen the client's dialplan so the new/removed custom
        // greeting branch takes effect immediately. Fan-out reload
        // hits every Asterisk backend.
        app(AsteriskConfigService::class)->pushConfig($this->record->id);
    }
}
