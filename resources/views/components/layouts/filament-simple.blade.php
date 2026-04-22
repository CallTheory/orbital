@php
    // All Fortify-backed auth pages render inside Filament's own
    // SimpleLayout shell by piggybacking on the PORTAL panel's context.
    // The login page is the single unified entry point for every user
    // on the platform (staff and tenants alike) — and since the portal
    // is the customer-facing identity, it's the brand and palette
    // customers should see when they land. Staff still authenticate
    // here; they just see portal branding while they do it.
    //
    // Filament's layout components assume `filament()` resolves to a
    // panel (brand, favicon, fonts, theme, colors, dark-mode script),
    // so we set and *boot* one here before rendering. Booting is
    // what materializes the panel's `->colors()` palette into the
    // CSS variables; without it, Filament falls back to its default
    // amber palette.
    //
    // The actual auth step still runs via Fortify's controllers under
    // routes/web.php; panel context here is purely for rendering.
    \Filament\Facades\Filament::setServingStatus();
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('portal'));

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
    // which would short-circuit the boot and leave our portal panel's
    // ->colors() palette unregistered.
    \Filament\Facades\Filament::getPanel('portal')->boot();

    $heading = $heading ?? null;
    $subheading = $subheading ?? null;
    $portalLogoLightUrl = \App\Support\Branding::portalLogoLightUrl();
    $portalLogoDarkUrl = \App\Support\Branding::portalLogoDarkUrl();
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
                            {{-- Dual-rendered logo: browser shows one
                                 per color mode via Filament's
                                 `dark:hidden` / `hidden dark:block`
                                 Tailwind utilities. The 512×128
                                 uploaded logo contains its own
                                 wordmark, so no separate text span. --}}
                            @php
                                // Same phrasing Filament uses in its own chrome:
                                // `:name logo` → e.g. "Customer Portal logo".
                                $brandAlt = __('filament-panels::layout.logo.alt', ['name' => filament()->getBrandName()]);
                            @endphp
                            <div class="orbital-brand">
                                <img src="{{ $portalLogoLightUrl }}"
                                     alt="{{ $brandAlt }}"
                                     class="orbital-brand-img orbital-brand-img-light">
                                <img src="{{ $portalLogoDarkUrl }}"
                                     alt="{{ $brandAlt }}"
                                     class="orbital-brand-img orbital-brand-img-dark">
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
