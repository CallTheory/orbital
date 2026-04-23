<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeGoalResource\Pages;

use App\Filament\Resources\IntakeGoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntakeGoal extends CreateRecord
{
    protected static string $resource = IntakeGoalResource::class;
}
