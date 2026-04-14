@php
    // All Fortify-backed auth pages render inside Filament's own
    // SimpleLayout shell by piggybacking on the admin panel's context.
    // Filament's layout components assume `filament()` resolves to a
    // panel (brand, favicon, fonts, theme, colors, fonts, dark-mode
    // script), so we set and *boot* one here before rendering.
    //
    // Booting is what materializes the panel's `->colors()` palette
    // into the CSS variables `--primary-*`, `--gray-*`, etc. Without
    // the boot call, Filament falls back to its default amber palette
    // even though the admin panel is configured for indigo.
    //
    // The actual auth step still runs via Fortify's controllers under
    // routes/web.php; panel context here is purely for rendering.
    \Filament\Facades\Filament::setServingStatus();
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

    // Clear ColorManager's internal cache before booting the panel.
    // ColorManager caches resolved colors on the first getColors() call,
    // and something in the early request pipeline (auth, middleware)
    // triggers that call before our blade runs — locking in Filament's
    // default amber palette. Unset the cache so Panel::boot()'s color
    // registration actually takes effect.
    $colorManager = app(\Filament\Support\Colors\ColorManager::class);
    (function () {
        unset($this->cachedColors);
    })->call($colorManager);

    // Call Panel::boot() directly instead of bootCurrentPanel() — the
    // manager's booted-flag may already be true from an earlier
    // getCurrentOrDefaultPanel() call during the request pipeline,
    // which would short-circuit the boot and leave our admin panel's
    // ->colors() palette unregistered.
    \Filament\Facades\Filament::getPanel('admin')->boot();

    $heading = $heading ?? null;
    $subheading = $subheading ?? null;
@endphp

<x-filament-panels::layout.base>
    <div class="fi-simple-layout">
        <div class="fi-simple-main-ctn">
            <main class="fi-simple-main fi-width-lg">
                <div class="fi-simple-page">
                    <div class="fi-simple-page-content">
                        {{-- Hand-built brand header rather than
                             <x-filament-panels::header.simple />.
                             That component calls <x-filament-panels::logo />
                             which wraps our custom HtmlString brand in an
                             `<div class="fi-logo" style="height: 1.75rem">`,
                             clipping the scaled-up logo and pushing the
                             wordmark out of the flex row. Emitting the
                             image + text ourselves keeps the simple-layout
                             scaling (4rem logo, 2.25rem text) intact. --}}
                        <header class="fi-simple-header">
                            <div class="orbital-brand">
                                <img src="{{ asset('images/orbital-logo.png') }}" alt="{{ filament()->getBrandName() }}" class="orbital-brand-img">
                                <span class="orbital-brand-text">{{ filament()->getBrandName() }}</span>
                            </div>

                            @if (filled($heading))
                                <h1 class="fi-simple-header-heading">
                                    {{ $heading }}
                                </h1>
                            @endif

                            @if (filled($subheading))
                                <p class="fi-simple-header-subheading">
                                    {{ $subheading }}
                                </p>
                            @endif
                        </header>

                        <x-filament::section>
                            {{ $slot }}
                        </x-filament::section>
                    </div>
                </div>
            </main>
        </div>
    </div>
</x-filament-panels::layout.base>
