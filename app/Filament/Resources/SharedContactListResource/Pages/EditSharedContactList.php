<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSharedContactList extends EditRecord
{
    protected static string $resource = SharedContactListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
