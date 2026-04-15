<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSharedContactLists extends ListRecords
{
    protected static string $resource = SharedContactListResource::class;

    protected ?string $subheading = 'Contact lists you manage once and attach to multiple tenants so every attached tenant sees the same entries.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
