<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSharedContactLists extends ListRecords
{
    protected static string $resource = SharedContactListResource::class;

    protected ?string $subheading = 'Contact lists sharable across multiple tenants.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
