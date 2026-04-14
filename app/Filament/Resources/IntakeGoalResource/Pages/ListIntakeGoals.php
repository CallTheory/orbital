<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeGoalResource\Pages;

use App\Filament\Resources\IntakeGoalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntakeGoals extends ListRecords
{
    protected static string $resource = IntakeGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
