<?php

declare(strict_types=1);

namespace App\Filament\Resources\LogoutReasonResource\Pages;

use App\Filament\Resources\LogoutReasonResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLogoutReason extends EditRecord
{
    protected static string $resource = LogoutReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
