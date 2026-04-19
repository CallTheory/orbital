<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsteriskBackendResource\Pages;

use App\Filament\Resources\AsteriskBackendResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAsteriskBackends extends ListRecords
{
    protected static string $resource = AsteriskBackendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
