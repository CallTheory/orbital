<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsteriskBackendResource\Pages;

use App\Filament\Resources\AsteriskBackendResource;
use Filament\Resources\Pages\EditRecord;

class EditAsteriskBackend extends EditRecord
{
    protected static string $resource = AsteriskBackendResource::class;

    protected function afterSave(): void
    {
        AsteriskBackendResource::regenerate();
    }
}
