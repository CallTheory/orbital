<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AvailabilityReason;
use App\Models\User;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Operator availability selector — pill rendered in the operator
 * panel topbar (left of the user menu) that lets the current
 * operator flip between any active row in the `availability_reasons`
 * table. The "I'm taking work" row is slug=`available`, seeded as
 * a regular row with `blocks_new_work=false` and protected against
 * deletion by the model layer.
 *
 * Side effects on change:
 *   1. Persist the status slug + stamp availability_changed_at
 *   2. Resync Asterisk queue_members for this operator so
 *      `paused` reflects the new state — calls stop ringing
 *      their softphone the moment they flip to a blocking
 *      status, and start ringing again when they flip back
 *   3. Dispatch `availability-updated` so the email inbox
 *      nav badge and any other listeners can refresh
 *
 * The currently-assigned work (calls already in progress, email
 * threads already claimed by this operator) stays with them —
 * the toggle only affects what NEW work routes to them.
 *
 * The whole vocabulary (including the Available row itself) is
 * editable by super-admins under Features → Availability Reasons.
 */
class AvailabilitySelector extends Component
{
    public string $status = AvailabilityReason::AVAILABLE;

    public function mount(): void
    {
        $this->status = auth()->user()?->availability_status
            ?? AvailabilityReason::AVAILABLE;
    }

    public function updatedStatus(string $value): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Accept any active reason slug. The Available row lives in
        // the table now, so it's already included — no extra push
        // needed. Anything not in the active set gets rejected:
        // Livewire shouldn't send unknown values from the UI, but
        // defend against direct component calls anyway.
        $allowedSlugs = AvailabilityReason::query()
            ->where('is_active', true)
            ->pluck('slug')
            ->all();

        if (! in_array($value, $allowedSlugs, true)) {
            $this->status = $user->availability_status;
            return;
        }

        $user->forceFill([
            'availability_status' => $value,
            'availability_changed_at' => now(),
        ])->save();

        // Re-sync Asterisk queue members so the paused flag
        // reflects this change. Cheap in practice because an
        // operator is usually a member of only a handful of
        // queues, and ARA writes are fast.
        app(QueueMemberSyncer::class)->syncForOperator($user);

        $body = $value === AvailabilityReason::AVAILABLE
            ? 'You\'re back on the pool — new calls and email will route to you.'
            : ($this->reasonBySlug($value)?->description
                ?: 'New work is paused until you flip back to Available.');

        Notification::make()
            ->title('Availability updated')
            ->body($body)
            ->success()
            ->send();

        // Broadcast so the email inbox nav badge + any other
        // availability-aware surfaces refresh in the same tick
        // without waiting for the next poll.
        $this->dispatch('availability-updated', status: $value);
        \Illuminate\Support\Facades\Cache::forget('operator:'.auth()->id().':email_inbox_badge');
        $this->dispatch('refresh-sidebar');
    }

    public function render()
    {
        $reasons = AvailabilityReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        // Every active row in the table, ordered by sort_order.
        // The `available` row is seeded at sort_order=0 so it
        // naturally leads the dropdown, and the admin can drag
        // other rows above or below it from the list page if
        // they want a different default. The dot_color flows
        // straight from the row so whatever the admin picked
        // in the color picker paints on the pill.
        $options = [];
        foreach ($reasons as $r) {
            $options[$r->slug] = [
                'label' => $r->label,
                'dot_color' => $r->dot_color,
            ];
        }

        // Fallback color for edge cases: a brand-new user whose
        // availability_status is still null, or the split-second
        // between is_active being flipped off on an operator's
        // current row and them picking a new one. Green matches
        // the default Available row color so nothing jumps
        // visually.
        $currentDotColor = $options[$this->status]['dot_color']
            ?? ($options[AvailabilityReason::AVAILABLE]['dot_color'] ?? '#22c55e');

        return view('livewire.availability-selector', [
            'options' => $options,
            'currentDotColor' => $currentDotColor,
        ]);
    }

    private function reasonBySlug(string $slug): ?AvailabilityReason
    {
        if ($slug === AvailabilityReason::AVAILABLE) {
            return null;
        }
        return AvailabilityReason::query()->where('slug', $slug)->first();
    }
}
