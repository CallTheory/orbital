<?php

return [
    // MUST boot before any Filament PanelProvider. Filament's internal
    // service providers resolve PanelRegistry during their boot pass,
    // which triggers each PanelProvider's resolving callback and runs
    // panel(). At that moment, config needs to already reflect the
    // platform_settings DB overrides (brand name, logo path, primary
    // color, etc.) — otherwise panel() reads the .env defaults, bakes
    // them in, and the admin-UI edits appear to not take effect.
    App\Providers\RuntimeConfigOverrideProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\OperatorPanelProvider::class,
    App\Providers\Filament\PortalPanelProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\JetstreamServiceProvider::class,
    // Replaces Pgvector\Laravel\PgvectorServiceProvider (disabled via
    // composer dont-discover) — registers the vector schema macros
    // without auto-loading the package's unconditional migration.
    App\Providers\PgvectorProvider::class,
    App\Providers\TelescopeServiceProvider::class,
];
