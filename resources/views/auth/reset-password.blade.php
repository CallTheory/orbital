<x-layouts.filament-simple
    heading="Set a new password"
    subheading="Enter your email and choose a new password."
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

    <form method="POST" action="{{ route('password.update') }}" class="fi-fo-component-ctn">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

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
                    value="{{ old('email', $request->email) }}"
                    required
                    autofocus
                    autocomplete="username"
                />
            </x-filament::input.wrapper>
        </div>

        <div class="fi-fo-field-wrp" style="margin-top: 1rem;">
            <div class="fi-fo-field-wrp-label-ctn">
                <label for="data.password" class="fi-fo-field-wrp-label">
                    <span>New password</span>
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
                    <span>Confirm new password</span>
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

        <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end;">
            <x-filament::button type="submit">
                Reset password
            </x-filament::button>
        </div>
    </form>
</x-layouts.filament-simple>
