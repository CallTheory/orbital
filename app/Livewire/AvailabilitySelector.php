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
 * operator toggle between "Available" and any "not taking work
 * right now" reason defined in the `availability_reasons` table.
 *
 * Side effects on change:
 *   1. Persist the status slug + stamp availability_changed_at
 *   2. Resync Asterisk queue_members for this operator so
 *      `paused` reflects the new state — calls stop ringing
 *      their softphone the moment they flip away from
 *      Available, and start ringing again when they flip back
 *   3. Dispatch `availability-updated` so the email inbox
 *      nav badge and any other listeners can refresh
 *
 * The currently-assigned work (calls already in progress, email
 * threads already claimed by this operator) stays with them —
 * the toggle only affects what NEW work routes to them.
 *
 * `available` is the implicit "taking work" state, always shown
 * at the top of the dropdown. Everything else comes from the
 * AvailabilityReason table and is editable by super-admins
 * under Platform → Availability Reasons.
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

        // Accept `available` plus any active reason slug. Anything
        // else gets rejected — UI shouldn't send unknown values,
        // but defend against direct Livewire calls anyway.
        $allowedSlugs = AvailabilityReason::query()
            ->where('is_active', true)
            ->pluck('slug')
            ->push(AvailabilityReason::AVAILABLE)
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
    }

    public function render()
    {
        $reasons = AvailabilityReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        // Always-on "Available" option at the top of the list,
        // followed by every active reason in sort order. The
        // `dot_color` gets passed through so the pill dot paints
        // correctly for whichever reason is currently selected.
        // The built-in `available` state has no row in the table,
        // so it ships a fixed green hex that matches the Filament
        // success color. Every other entry passes through the
        // admin-picked hex from AvailabilityReason::dot_color.
        $options = [
            AvailabilityReason::AVAILABLE => [
                'label' => 'Available',
                'dot_color' => '#22c55e',
            ],
        ];
        foreach ($reasons as $r) {
            $options[$r->slug] = [
                'label' => $r->label,
                'dot_color' => $r->dot_color,
            ];
        }

        return view('livewire.availability-selector', [
            'options' => $options,
            'currentDotColor' => $options[$this->status]['dot_color']
                ?? $options[AvailabilityReason::AVAILABLE]['dot_color'],
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
