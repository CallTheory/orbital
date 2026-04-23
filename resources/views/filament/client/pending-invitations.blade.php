@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ClientInvitation> $invitations */
@endphp
<div style="display: flex; flex-direction: column; gap: 0.5rem;">
    @if($invitations->isEmpty())
        <div style="padding: 1rem; text-align: center; color: var(--gray-500); font-size: 0.875rem;">
            No pending invitations.
        </div>
    @else
        @foreach($invitations as $invite)
            <div style="border: 1px solid var(--gray-200); border-radius: 0.5rem; padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                <div style="display: flex; flex-direction: column; gap: 0.125rem;">
                    <span style="font-weight: 600;">{{ $invite->email }}</span>
                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                        Role: {{ str_replace('_', ' ', $invite->role) }}
                        · Sent {{ $invite->created_at?->diffForHumans() }}
                        @if($invite->expires_at)
                            · Expires {{ $invite->expires_at->diffForHumans() }}
                        @endif
                    </span>
                </div>
                <div style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; color: var(--gray-400);">
                    {{ Str::of($invite->token)->substr(0, 12) }}…
                </div>
            </div>
        @endforeach
        <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
            Resend / cancel controls wire up alongside the acceptance flow in phase 4.
        </p>
    @endif
</div>
