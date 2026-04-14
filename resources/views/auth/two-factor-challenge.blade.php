<x-layouts.filament-simple
    heading="Two-factor challenge"
    subheading="Enter the code from your authenticator app to continue."
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

    <div x-data="{ recovery: false }">
        <form method="POST" action="{{ route('two-factor.login') }}" class="fi-fo-component-ctn">
            @csrf

            <div class="fi-fo-field-wrp" x-show="! recovery">
                <div class="fi-fo-field-wrp-label-ctn">
                    <label for="data.code" class="fi-fo-field-wrp-label">
                        <span>Authentication code</span>
                    </label>
                </div>
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        inputmode="numeric"
                        id="data.code"
                        name="code"
                        x-ref="code"
                        autocomplete="one-time-code"
                        autofocus
                    />
                </x-filament::input.wrapper>
            </div>

            <div class="fi-fo-field-wrp" x-cloak x-show="recovery">
                <div class="fi-fo-field-wrp-label-ctn">
                    <label for="data.recovery_code" class="fi-fo-field-wrp-label">
                        <span>Recovery code</span>
                    </label>
                </div>
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        id="data.recovery_code"
                        name="recovery_code"
                        x-ref="recovery_code"
                        autocomplete="one-time-code"
                    />
                </x-filament::input.wrapper>
            </div>

            <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                <button
                    type="button"
                    x-show="! recovery"
                    x-on:click="recovery = true; $nextTick(() => $refs.recovery_code.focus())"
                    style="background: none; border: 0; padding: 0; cursor: pointer;"
                >
                    <x-filament::link tag="span">Use a recovery code</x-filament::link>
                </button>
                <button
                    type="button"
                    x-cloak
                    x-show="recovery"
                    x-on:click="recovery = false; $nextTick(() => $refs.code.focus())"
                    style="background: none; border: 0; padding: 0; cursor: pointer;"
                >
                    <x-filament::link tag="span">Use an authentication code</x-filament::link>
                </button>

                <x-filament::button type="submit">
                    Sign in
                </x-filament::button>
            </div>
        </form>
    </div>
</x-layouts.filament-simple>
