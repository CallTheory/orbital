<?php

declare(strict_types=1);

namespace App\Filament\Resources\SipTrunkResource\Pages;

use App\Filament\Resources\SipTrunkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSipTrunk extends EditRecord
{
    protected static string $resource = SipTrunkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
