<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsteriskBackendResource\Pages;

use App\Filament\Resources\AsteriskBackendResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAsteriskBackend extends CreateRecord
{
    protected static string $resource = AsteriskBackendResource::class;

    protected function afterCreate(): void
    {
        AsteriskBackendResource::regenerate();
    }
}
