<x-layouts.filament-simple
    heading="Reset your password"
    subheading="Enter your email address and we'll send you a password reset link."
>
    @if ($errors->any())
        <div style="color: rgb(239 68 68); margin-bottom: 1rem; font-size: 0.875rem;">
            <ul style="margin: 0; padding-left: 1rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @session('status')
        <div style="color: rgb(16 185 129); margin-bottom: 1rem; font-size: 0.875rem;">
            {{ $value }}
        </div>
    @endsession

    <form method="POST" action="{{ route('password.email') }}" class="fi-fo-component-ctn">
        @csrf

        <div class="fi-fo-field-wrp">
            <div class="fi-fo-field-wrp-label-ctn">
                <label for="data.email" class="fi-fo-field-wrp-label">
                    <span>Email address</span>
                    <sup class="fi-fo-field-wrp-required-mark">*</sup>
                </label>
            </div>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="email"
                    id="data.email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="username"
                />
            </x-filament::input.wrapper>
        </div>

        <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <x-filament::link :href="route('login')">
                Back to sign in
            </x-filament::link>
            <x-filament::button type="submit">
                Email reset link
            </x-filament::button>
        </div>
    </form>
</x-layouts.filament-simple>
