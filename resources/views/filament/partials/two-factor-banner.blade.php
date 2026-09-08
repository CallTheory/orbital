{{--
    Two-factor countdown banner.

    Rendered into BODY_START on all three panels for any signed-in user
    who hasn't enrolled. Turns from warning to danger in the last 48
    hours, and disappears the moment a second factor is confirmed.

    Cheap on purpose: config reads plus at most one write (the first
    request, to stamp the grace start). No queries beyond the already
    loaded user.
--}}
@php
    $user = auth()->user();
    $policy = app(\App\Services\Auth\TwoFactorPolicy::class);
@endphp

@if ($user && $policy->shouldWarn($user))
    @php
        $urgent = $policy->isUrgent($user);
        $blocked = $policy->isBlocked($user);
        $days = $policy->daysRemaining($user);
        $hours = $policy->hoursRemaining($user);
        $securityUrl = $policy->securityUrlFor($user);
    @endphp

    <div style="
        padding: 0.625rem 1rem;
        font-size: 0.8125rem;
        line-height: 1.4;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        background: {{ $urgent ? 'var(--danger-50, rgba(239,68,68,0.10))' : 'var(--warning-50, rgba(245,158,11,0.10))' }};
        color: {{ $urgent ? 'var(--danger-700, #b91c1c)' : 'var(--warning-700, #b45309)' }};
        border-bottom: 1px solid {{ $urgent ? 'var(--danger-300, rgba(239,68,68,0.35))' : 'var(--warning-300, rgba(245,158,11,0.35))' }};
    ">
        <span>
            @if ($blocked)
                <strong>Two-factor authentication is required.</strong>
                Set it up now to continue.
            @elseif ($hours <= 48)
                <strong>{{ $hours }} {{ \Illuminate\Support\Str::plural('hour', $hours) }} left</strong>
                to enable two-factor authentication.
            @else
                <strong>{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} left</strong>
                to enable two-factor authentication.
            @endif
        </span>

        <a href="{{ $securityUrl }}" style="text-decoration: underline; font-weight: 600;">
            Set it up
        </a>
    </div>
@endif
