<x-layouts.filament-simple
    heading="Create your account"
    subheading="Register a new account to continue."
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

    <form method="POST" action="{{ route('register') }}" class="fi-fo-component-ctn">
        @csrf

        <div class="fi-fo-field-wrp">
            <div class="fi-fo-field-wrp-label-ctn">
                <label for="data.name" class="fi-fo-field-wrp-label">
                    <span>Full name</span>
                    <sup class="fi-fo-field-wrp-required-mark">*</sup>
                </label>
            </div>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    id="data.name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    autofocus
                    autocomplete="name"
                />
            </x-filament::input.wrapper>
        </div>

        <div class="fi-fo-field-wrp" style="margin-top: 1rem;">
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
                    autocomplete="new-password"
                />
            </x-filament::input.wrapper>
        </div>

        <div class="fi-fo-field-wrp" style="margin-top: 1rem;">
            <div class="fi-fo-field-wrp-label-ctn">
                <label for="data.password_confirmation" class="fi-fo-field-wrp-label">
                    <span>Confirm password</span>
                    <sup class="fi-fo-field-wrp-required-mark">*</sup>
                </label>
            </div>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="password"
                    id="data.password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </x-filament::input.wrapper>
        </div>

        @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
            <div style="margin-top: 1rem; font-size: 0.875rem;">
                <label for="terms" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                    <x-filament::input.checkbox id="terms" name="terms" required />
                    <span>
                        I agree to the
                        <x-filament::link target="_blank" :href="route('terms.show')">Terms of Service</x-filament::link>
                        and
                        <x-filament::link target="_blank" :href="route('policy.show')">Privacy Policy</x-filament::link>
                    </span>
                </label>
            </div>
        @endif

        <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <x-filament::link :href="route('login')">
                Already registered? Sign in
            </x-filament::link>
            <x-filament::button type="submit">
                Register
            </x-filament::button>
        </div>
    </form>
</x-layouts.filament-simple>
