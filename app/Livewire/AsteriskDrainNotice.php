<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Listens for the `AsteriskDrainInitiated` Reverb broadcast and
 * pops a persistent Filament toast to operators whose softphone
 * is actually on the draining Asterisk.
 *
 * The component doesn't render anything itself — its job is to
 * sit in the operator panel's render tree, receive the Echo
 * event, and dispatch a Filament notification that the panel's
 * toast stack picks up and displays. The toast stays on screen
 * until the operator clicks it away (`persistent()`).
 *
 * Filtering: the event payload carries the list of endpoint IDs
 * currently registered on the draining node (from ps_contacts
 * WHERE reg_server = $backend). We compare against the logged-in
 * user's assigned extensions and only fire the toast for a
 * match. Operators on the surviving node see nothing because
 * nothing about their session changes.
 */
class AsteriskDrainNotice extends Component
{
    public function mount(): void
    {
        // Diagnostic breadcrumb: confirms the component was
        // actually mounted on this page. If we don't see this
        // log line on the operator panel but do on admin, it
        // means the operator panel never renders the component
        // (render-hook / auth / layout issue).
        Log::info('asterisk-drain-notice: mounted', [
            'user_id' => auth()->id(),
            'panel' => request()->route()?->getName(),
            'url' => request()->fullUrl(),
        ]);
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return $userId
            ? ["echo-private:operator.drain.{$userId},.asterisk-drain-initiated" => 'onDrainInitiated']
            : [];
    }

    public function onDrainInitiated(array $payload = []): void
    {
        // Echo wraps the event payload in an array — same
        // unwrap pattern as SystemStatusBar.
        $data = $payload[0] ?? $payload;

        Log::info('asterisk-drain-notice: received event', [
            'user_id' => auth()->id(),
            'url' => request()->fullUrl(),
            'backend' => $data['backend'] ?? null,
        ]);

        // Operators don't need to know which Asterisk — the
        // server name is an infrastructure detail. The action is
        // what matters: finish the current call, then hit Ctrl+R.
        // A full page refresh rebuilds the WSS through HAProxy,
        // which routes them to the surviving node automatically.
        //
        // `id()` keyed on the event's timestamp dedupes if Echo
        // double-delivers or Livewire's SPA-navigation leaves
        // multiple mounts of this component listening — two
        // identical IDs hit Filament's "update, don't stack"
        // path so the operator sees one toast per drain.
        $drainId = 'asterisk-drain-' . (string) ($data['backend'] ?? 'unknown')
            . '-' . (string) ($data['at'] ?? (string) time());

        $notification = Notification::make($drainId)
            ->title('Maintenance starting on your telephony server')
            ->body(
                'Please finish your current call, then refresh '.
                'this page (Ctrl+R) to reconnect through the '.
                'surviving server.',
            )
            ->icon('heroicon-o-wrench-screwdriver')
            ->iconColor('warning')
            ->warning()
            ->persistent();

        // Filament's Notification::send() only flashes to session —
        // it relies on Livewire dispatching `notificationsSent`
        // automatically at the end of a normal component update.
        // That auto-dispatch does NOT fire when the update is
        // triggered by an Echo broadcast (no wire:click, no page
        // navigation — just a silent Livewire commit). Dispatch the
        // `notificationSent` browser event ourselves with the
        // notification payload so the Filament toast stack on this
        // page pops immediately, without needing a page refresh.
        $this->dispatch('notificationSent', notification: $notification->toArray());
    }

    public function render()
    {
        return view('livewire.asterisk-drain-notice');
    }
}
