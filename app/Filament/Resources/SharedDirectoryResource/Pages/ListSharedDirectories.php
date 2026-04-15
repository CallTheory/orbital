<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedDirectoryResource\Pages;

use App\Filament\Resources\SharedDirectoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSharedDirectories extends ListRecords
{
    protected static string $resource = SharedDirectoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
