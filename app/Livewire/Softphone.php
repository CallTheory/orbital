<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Extension;
use Livewire\Component;

class Softphone extends Component
{
    public function getSipConfig(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        // Find the user's WebRTC extension
        $extension = Extension::where('type', 'webrtc')
            ->where('assignable_type', $user->getMorphClass())
            ->where('assignable_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $extension) {
            return [];
        }

        return [
            'wsUrl' => config('telephony.asterisk.wss_url'),
            'domain' => config('telephony.asterisk.sip_domain'),
            'username' => $extension->sip_username ?? $extension->number,
            'password' => $extension->sip_password ?? '',
        ];
    }

    public function render()
    {
        return view('components.softphone', [
            'sipConfig' => $this->getSipConfig(),
        ]);
    }
}
