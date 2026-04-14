{{-- Sign-in page. Rendered inside Filament's SimpleLayout chrome
     (see layouts.filament-simple) so the UI is pixel-identical to
     every other Filament surface. The form still posts to the
     Fortify-managed POST /login route, which is what actually
     authenticates the user and runs our LoginResponse to route
     them to their home panel. --}}
<x-layouts.filament-simple heading="Sign in to your account" subheading="Enter your credentials to continue.">

    @if ($errors->any())
        <div class="fi-fo-field-wrp-error-message" style="color: rgb(239 68 68); margin-bottom: 1rem; font-size: 0.875rem;">
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

    <form method="POST" action="{{ route('login') }}" class="fi-fo-component-ctn">
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

        <div class="fi-fo-field-wrp" style="margin-top: 1rem;">
            <div class="fi-fo-field-wrp-label-ctn">
                <label for="data.password" class="fi-fo-field-wrp-label">
                    <span>Password</span>
                    <sup class="fi-fo-field-wrp-required-mark">*</sup>
                </label>
            </div>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="password"
                    id="data.password"
                    name="password"
                    required
                    autocomplete="current-password"
                />
            </x-filament::input.wrapper>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; font-size: 0.875rem;">
            <label for="remember_me" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <x-filament::input.checkbox id="remember_me" name="remember" />
                <span>Remember me</span>
            </label>

            @if (Route::has('password.request'))
                <x-filament::link :href="route('password.request')">
                    Forgot your password?
                </x-filament::link>
            @endif
        </div>

        <div style="margin-top: 1.5rem;">
            <x-filament::button type="submit" size="lg" class="w-full" style="width: 100%;">
                Sign in
            </x-filament::button>
        </div>
    </form>
</x-layouts.filament-simple>
