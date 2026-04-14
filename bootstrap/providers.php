<?php

return [
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
    App\Providers\RuntimeConfigOverrideProvider::class,
    App\Providers\TelescopeServiceProvider::class,
];
