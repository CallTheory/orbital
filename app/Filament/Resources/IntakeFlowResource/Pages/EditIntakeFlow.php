<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeFlowResource\Pages;

use App\Filament\Resources\IntakeFlowResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIntakeFlow extends EditRecord
{
    protected static string $resource = IntakeFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
