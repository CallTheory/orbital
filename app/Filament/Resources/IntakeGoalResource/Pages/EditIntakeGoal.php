<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeGoalResource\Pages;

use App\Filament\Resources\IntakeGoalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIntakeGoal extends EditRecord
{
    protected static string $resource = IntakeGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
