<x-layouts.filament-simple
    heading="Confirm password"
    subheading="Secure area — please re-enter your password to continue."
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

    <form method="POST" action="{{ route('password.confirm') }}" class="fi-fo-component-ctn">
        @csrf

        <div class="fi-fo-field-wrp">
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
                    autofocus
                />
            </x-filament::input.wrapper>
        </div>

        <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end;">
            <x-filament::button type="submit">
                Confirm
            </x-filament::button>
        </div>
    </form>
</x-layouts.filament-simple>
