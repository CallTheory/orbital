<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OperatorPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\PgvectorProvider;
use App\Providers\RuntimeConfigOverrideProvider;
use App\Providers\TelescopeServiceProvider;

return [
    // MUST boot before any Filament PanelProvider. Filament's internal
    // service providers resolve PanelRegistry during their boot pass,
    // which triggers each PanelProvider's resolving callback and runs
    // panel(). At that moment, config needs to already reflect the
    // platform_settings DB overrides (brand name, logo path, primary
    // color, etc.) — otherwise panel() reads the .env defaults, bakes
    // them in, and the admin-UI edits appear to not take effect.
    RuntimeConfigOverrideProvider::class,
    // MUST come after RuntimeConfigOverrideProvider: it maps
    // observability.* onto the vendor SDKs from a booting() callback,
    // and those callbacks fire in registration order. Registered first
    // it would read .env and never see an operator's admin-UI setting.
    ObservabilityServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    OperatorPanelProvider::class,
    PortalPanelProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    JetstreamServiceProvider::class,
    // Replaces Pgvector\Laravel\PgvectorServiceProvider (disabled via
    // composer dont-discover) — registers the vector schema macros
    // without auto-loading the package's unconditional migration.
    PgvectorProvider::class,
    TelescopeServiceProvider::class,
];
