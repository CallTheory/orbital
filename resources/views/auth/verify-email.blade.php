<x-layouts.filament-simple
    heading="Verify your email"
    subheading="Click the link we just emailed you. You can resend it below if it didn't arrive."
>
    @if (session('status') == 'verification-link-sent')
        <div style="color: rgb(16 185 129); margin-bottom: 1rem; font-size: 0.875rem;">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-filament::button type="submit">
                Resend verification email
            </x-filament::button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" style="background: none; border: 0; padding: 0; cursor: pointer;">
                <x-filament::link tag="span">Sign out</x-filament::link>
            </button>
        </form>
    </div>
</x-layouts.filament-simple>
