<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeGoalResource\Pages;

use App\Filament\Resources\IntakeGoalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntakeGoals extends ListRecords
{
    protected static string $resource = IntakeGoalResource::class;

    protected ?string $subheading = 'Reusable building blocks — talking points, data fields, completion rules, tools — that tenants compose into their intake flows.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
